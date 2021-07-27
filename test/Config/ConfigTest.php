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
        $config = new Config($this->_config);
        $this->assertNotEmpty($config);
    }

    /**
     * @throws Exception
     */
    public function testIsMainProcessNameInConfig()
    {
        $config = new Config($this->_config);
        $this->assertTrue($config->isMainProcessNameInConfig('DependentOneTask'));
    }

    /**
     * @throws Exception
     */
    public function testIsMainProcessNameInConfigDisabled()
    {
        $configDisabled = [
            'mainProcesses' => [
                'mainProcess1' => [
                    'disabled' => true
                ]
            ]
        ];

        $config = new Config(array_merge_recursive($this->_config, $configDisabled));
        $this->assertFalse($config->isMainProcessNameInConfig('DependentOneTask'));
    }

    /**
     * @throws Exception
     */
    public function testGetMainProcessNames()
    {
        $config = new Config($this->_config);
        $this->assertCount(4, $config->getMainProcessNames());
    }

    /**
     * @throws Exception
     */
    public function testGetMainProcessNamesDisabled()
    {
        $configDisabled = [
            'mainProcesses' => [
                'mainProcess2' => [
                    'disabled' => true
                ],
                'mainProcess3' => [
                    'disabled' => true
                ]
            ]
        ];

        $config = new Config(array_merge_recursive($this->_config, $configDisabled));
        $this->assertCount(2, $config->getMainProcessNames());
    }
}