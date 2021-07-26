<?php

namespace Crystal\Exception;

use Throwable;
use Exception;

class CrystalTaskStateErrorException extends Exception
{
    public static $errorCodesMessages = [
        100 => 'RunningToNewStateChangeStrategy encountered, already picked up'
    ];

    public function __construct(string $message = '', int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}