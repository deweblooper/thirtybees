<?php
/**
 * Copyright (C) 2019 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2019 thirty bees
 * @license   Open Software License (OSL 3.0)
 */

/**
 * Class PackageManagerCore
 */
class PackageManagerCore
{
    /**
     * @var PackageRepository[] list of repositories
     */
    protected $repositories = null;

    /**
     * Returns all registered repositories
     *
     * @return PackageRepository[]
     * @throws PrestaShopException
     */
    public function getRepositories()
    {
        if (is_null($this->repositories)) {
            // default thirty bees package repository
            $this->repositories = [
                new PackageRepository('thirty bees modules', 'https://api.thirtybees.com/updates/modules/all.json')
            ];

            // register custom package repositories
            $modules = Hook::exec('actionGetPackageRepository', [], null, true);
            if ($modules) {
                foreach ($modules as $moduleId => $repo) {
                    if ($repo instanceof PackageRepository) {
                        $this->repositories[] = $repo;
                    } else if (is_array($repo) && isset($repo['name']) && isset($repo['url'])) {
                        $repositoryName = $repo['name'];
                        $repositoryUrl = $repo['url'];
                        $this->repositories[] = new PackageRepository($repositoryName, $repositoryUrl);
                    } else {
                        Logger::addLog("Module $moduleId provided invalid package repository");
                    }
                }
            }
        }
        return $this->repositories;
    }

    /**
     * @throws PrestaShopException
     */
    public function getPackages()
    {
        $result = [];
        foreach ($this->getRepositories() as $repository) {
            $result[] = $repository->getPackages();
        }
        return $result;
    }

    /**
     * @throws PrestaShopException
     */
    public function refreshPackages()
    {
        $result = true;
        foreach ($this->getRepositories() as $repository) {
            $result = $repository->refreshRepository() && $result;
        }
        return $result;
    }

}
