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
 * Class PackageRepositoryCore
 */
class PackageRepositoryCore
{
    /**
     * @var string repository name list of packages
     */
    protected $name;

    /**
     * @var string url list of packages
     */
    public $url;

    /**
     * PackageRepositoryCore constructor.
     *
     * @param $name
     * @param string $url
     */
    public function __construct($name, $url)
    {
        $this->name = $name;
        $this->url = $url;
    }

    /**
     * Returns list of packages provided by this repository
     *
     * This method always returns cached version of list.
     *
     * If you need fresh copy, use refreshRepository method
     *
     * @return array
     */
    public function getPackages()
    {
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            return $this->parsePackages(@file_get_contents($cacheFile));
        }
        return [];
    }

    /**
     * Refresh repository cache
     *
     * @return bool
     */
    public function refreshRepository()
    {
        return Tools::copy($this->url, $this->getCacheFile());
    }

    /**
     * @return string
     */
    protected function getCacheFile()
    {
        $filename = $this->name ? $this->name : 'unnamed';
        $filename = preg_replace('/[^a-zA-Z0-9\s\'\:\/\[\]\-]/', '', $filename);
        $filename = preg_replace('/[\s\'\:\/\[\]\-]+/', ' ', $filename);
        $filename = str_replace([' ', '/'], '_', $filename);
        $filename = mb_substr(mb_strtolower($filename), 0, 60);
        $filename = $filename . '.' . Tools::encrypt($this->name . ' ' . $this->url) . '.json';
        return _PS_CACHE_DIR_ . $filename;
    }

    /**
     * @param string $stringContent
     * @return array
     */
    protected function parsePackages($stringContent)
    {
        if ($stringContent) {
            return json_decode($stringContent, true);
        }
        return [];
    }
}
