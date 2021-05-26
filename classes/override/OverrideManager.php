<?php

class OverrideManagerCore
{

    /**
     * @var OverrideManager instance
     */
    protected static $instance;

    /**
     * @var
     */
    protected $cache = [];

    /**
     * @return OverrideManager
     */
    public static function getInstance()
    {
        if (! static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * OverrideManager constructor
     */
    protected function __construct()
    {
    }

    /**
     *
     */
    public function refresh()
    {
        $this->cache = [];
    }

    /**
     * @param $moduleName
     * @return OverridePatch
     */
    public function createPatch($moduleName)
    {
        return new OverridePatch($this, $moduleName);
    }

}