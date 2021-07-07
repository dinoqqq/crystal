<?php

namespace Crystal\Test\RangeStrategy;

use Crystal\RangeStrategy\HashRangeStrategy;
use Crystal\Test\Core\BaseTest;
use Exception;

class HashRangeStrategyTest extends BaseTest
{
    private $_hashRangeStrategy;

    public function setUp()
    {
        parent::setUp();
        $this->_hashRangeStrategy = new HashRangeStrategy();
    }

    /**
     * Should throw when no data is set yet
     */
    public function testNoDataSet()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('First run setData');

        $this->_hashRangeStrategy->calculateRange();
    }

    /**
     * Should throw when try to set resources = 0
     *
     * @throws Exception
     */
    public function testSetDataWithInvalidResources()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The HashRangeStrategy can currently not work with 0 resources');
        $this->_hashRangeStrategy->setData(['resources' => 0]);
    }

    /**
     * Should divide with a no module number
     *
     * @throws Exception
     */
    public function testCalculateRangeNoModulo()
    {
        $result = [
            '01234567',
            '89abcdef',
        ];
        $this->_hashRangeStrategy->setData(['resources' => 2]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }

    /**
     * Should divide with a no module number
     *
     * @throws Exception
     */
    public function testCalculateRangeModulo()
    {
        $result = [
            '012345',
            '6789a',
            'bcdef',
        ];
        $this->_hashRangeStrategy->setData(['resources' => 3]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }

    /**
     * Should divide with a no module number 2
     *
     * @throws Exception
     */
    public function testCalculateRangeModulo2()
    {
        $result = [
            '0123',
            '456',
            '789',
            'abc',
            'def',
        ];
        $this->_hashRangeStrategy->setData(['resources' => 5]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }

    /**
     * Should divide with a no module number
     *
     * @throws Exception
     */
    public function testCalculateRangeTopBorder()
    {
        $result = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];
        $this->_hashRangeStrategy->setData(['resources' => 16]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }

    /**
     * Should work with number 10
     *
     * @throws Exception
     */
    public function testCalculateRangeNumber10()
    {
        $result = ['01', '23', '45', '67', '89', 'ab', 'c', 'd', 'e', 'f'];
        $this->_hashRangeStrategy->setData(['resources' => 10]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }

    /**
     * Should work with number 15
     *
     * @throws Exception
     */
    public function testCalculateRangeNumber15()
    {
        $result = ['01', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f'];
        $this->_hashRangeStrategy->setData(['resources' => 15]);
        $this->assertEquals($result, $this->_hashRangeStrategy->calculateRange());
    }
}
