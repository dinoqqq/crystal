<?php


namespace Crystal\Test\Config;


use Crystal\Config\Config;
use Crystal\Test\Core\BaseTestApp;
use Exception;

class ConfigTest extends BaseTestApp
{
    private $_config;

    public function setUp()
    {
        parent::setUp();

        $this->_config = array_merge($this->getDatabaseConfig(), $this->getGlobalConfig());
    }

    /**
     * @throws Exception
     */
    public function testConfigShouldNotThrowAnError()
    {
        new Config($this->_config);
        $this->assertTrue(true);
    }
}