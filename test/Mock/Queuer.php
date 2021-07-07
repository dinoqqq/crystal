<?php

namespace Crystal\Test\Mock;

use Crystal\Heartbeat\QueuerInterface;
use Crystal\MainProcess\MainProcess;
use Exception;

class Queuer implements QueuerInterface
{
    public function getNextMainProcesses(): array
    {
        return [];
    }

    public function queueingStart(MainProcess $mainProcess): bool
    {
        return true;
    }

    public function queueingStop(MainProcess $mainProcess): bool
    {
        return true;
    }

    public function queueingFailed(MainProcess $mainProcess, Exception $e): void
    {
    }

}