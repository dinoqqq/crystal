<?php


namespace Crystal\Test\Exception;


use Crystal\Exception\CrystalTaskStateErrorException;
use Crystal\Test\Core\BaseTestApp;

class CrystalTaskStateErrorExceptionTest extends BaseTestApp
{
    public function setUp()
    {
        parent::setUp();
    }

    public function testExceptionErrorMessages()
    {
        $code = 100;
        $message = CrystalTaskStateErrorException::$errorCodesMessages[$code];
        $this->expectException(CrystalTaskStateErrorException::class);
        $this->expectExceptionCode($code);
        $this->expectExceptionMessage($message);

        throw new CrystalTaskStateErrorException($message, $code);
    }

}