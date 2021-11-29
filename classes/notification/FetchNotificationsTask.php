<?php
/**
 * Copyright (C) 2021-2021 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2021-2021 thirty bees
 * @license   Open Software License (OSL 3.0)
 */

namespace Thirtybees\Core\Notification;

use Adapter_Exception;
use Configuration;
use Context;
use Db;
use PrestaShopException;
use Thirtybees\Core\InitializationCallback;
use Thirtybees\Core\WorkQueue\ScheduledTask;
use Thirtybees\Core\WorkQueue\WorkQueueContext;
use Thirtybees\Core\WorkQueue\WorkQueueTask;
use Thirtybees\Core\WorkQueue\WorkQueueTaskCallable;
use Validate;

/**
 * Class FetchNotificationsTaskCore
 *
 * Work queue task that collects information and sends them to thirty bees api server
 *
 * @since 1.3.0
 */
class FetchNotificationsTaskCore implements WorkQueueTaskCallable, InitializationCallback
{
    /**
     * Returns work queue task for this callable
     *
     * @return WorkQueueTask
     * @throws Adapter_Exception
     * @throws PrestaShopException
     */
    public static function createTask()
    {
        return WorkQueueTask::createTask(
            static::class,
            [],
            WorkQueueContext::fromContext(Context::getContext())
        );
    }

    /**
     * Task execution method
     *
     * Collect data using all extractors that store owner gave consent. If any data are available,
     * send them to thirty bees api server
     *
     *
     * @param WorkQueueContext $context
     * @param array $parameters
     *
     * @return string
     * @throws PrestaShopException
     * @throws Adapter_Exception
     */
    public function execute(WorkQueueContext $context, array $parameters)
    {
        $lastUuid = $this->getLastSeenNotificationUuid();
        $data = $this->fetch($lastUuid);
        $cnt = 0;
        if ($data) {
            foreach ($data as $entry) {
                $cnt++;
                $uuid = static::getProperty('uuid', $entry);
                $conditions = static::getProperty('conditions', $entry, []);
                $lastUuid = $uuid;
                if ($this->acceptNotification($conditions)) {
                    $notification = SystemNotification::getByUuid($uuid);
                    if (! Validate::isLoadedObject($notification)) {
                        $notification = new SystemNotification();
                    }
                    $notification->uuid = $uuid;
                    $notification->importance = static::getProperty('importance', $entry);
                    $notification->title = static::getProperty('title', $entry);
                    $notification->message = static::getProperty('message', $entry);
                    $notification->date_created = date('Y-m-d', strtotime(static::getProperty('date', $entry)));
                    $notification->save();
                }
            }
            $this->setLastSeenNotificationUuid($lastUuid);
        }
        return "Retrieved $cnt notifications";
    }

    /**
     * Retrieves notifications from thirty bees api server
     *
     * @throws PrestaShopException
     */
    protected function fetch($lastUuid)
    {
        $guzzle = new \GuzzleHttp\Client([
            'base_uri'    => Configuration::getApiServer(),
            'timeout'     => 15,
            'verify'      => Configuration::getSslTrustStore()
        ]);
        $response = $guzzle->post(
            '/notification/v1.php',
            [
                'json' => [
                    'sid' => Configuration::getServerTrackingId(),
                    'ts' => time(),
                    'lastSeen' => $lastUuid,
                ]
            ]
        );

        if ($response->getStatusCode() >= 300) {
            throw new PrestaShopException("Invalid response status code: " . $response->getStatusCode() . ' ' . $response->getReasonPhrase());
        }

        $body =$response->getBody();
        if (! $body) {
            throw new PrestaShopException("Empty response");
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new PrestaShopException("Failed to parse response: " . $body);
        }

        if (! isset($json['success'])) {
            throw new PrestaShopException("Invalid response payload: " . $body);
        }

        if (! $json['success']) {
            if (isset($json['error'])) {
                throw new PrestaShopException($json['error']);
            } else {
                throw new PrestaShopException("Failure response: " . $body);
            }
        }

        return static::getProperty('data', $json);
    }

    /**
     * Returns true, if this store accepts notification conditions
     *
     * @param array $conditionGroups array of arrays
     */
    protected function acceptNotification($conditionGroups)
    {
        if ($conditionGroups) {
            // at least one condition group must be satisfied
            foreach ($conditionGroups as $conditions) {
                if ($this->allConditionsSatisfied($conditions)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    /**
     * Return true, if all conditions are satisfied
     *
     * @param array $conditions
     * @return bool
     */
    protected function allConditionsSatisfied($conditions)
    {
        foreach ($conditions as $condition) {
            if (! $this->conditionSatisfied($condition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return true, if condition is satisfied
     *
     * @param array $condition
     */
    protected function conditionSatisfied($condition)
    {
        // TODO: implement condition evaluation
        return true;
    }

    /**
     * Returns last seen notification UUID
     *
     * @return string
     */
    protected function getLastSeenNotificationUuid()
    {
        $value = Configuration::getGlobalValue(Configuration::LAST_SEEN_NOTIFICATION_UUID);
        if ($value) {
            return $value;
        }
        return null;
    }

    /**
     * Updates last seen notification UUID
     *
     * @param string $uuid Last seen notification UUID
     * @throws PrestaShopException
     */
    protected function setLastSeenNotificationUuid($uuid)
    {
        Configuration::updateGlobalValue(Configuration::LAST_SEEN_NOTIFICATION_UUID, $uuid);
    }

    /**
     * Extracts value of $entry[$key], if exists
     *
     * @param string $key
     * @param array $entry
     * @param mixed $defaultValue
     * @return mixed
     * @throws PrestaShopException
     */
    protected static function getProperty($key, array $entry, $defaultValue=null)
    {
        if (array_key_exists($key, $entry)) {
            return $entry[$key];
        }
        if (! is_null($defaultValue)) {
            return $defaultValue;
        }
        throw new PrestaShopException("Property '$key' not found");
    }

    /**
     * Callback method to initialize class
     *
     * @param Db $conn
     * @return void
     * @throws PrestaShopException
     */
    public static function initializationCallback(Db $conn)
    {
        $task = str_replace("FetchNotificationTaskCore", "FetchNotificationTask", static::class);
        $trackingTasks = ScheduledTask::getTasksForCallable($task);
        if (! $trackingTasks) {
            $scheduledTask = new ScheduledTask();
            $scheduledTask->frequency = rand(0, 59) . ' */6 * * *';
            $scheduledTask->name = 'Thirty bees notification task';
            $scheduledTask->description = 'Retrieve thirty bees notifications from api server';
            $scheduledTask->task = $task;
            $scheduledTask->active = true;
            $scheduledTask->add();
        }
    }

}

