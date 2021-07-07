<?php


namespace Crystal\Heartbeat;


use Crystal\MainProcess\MainProcess;
use Exception;

interface QueuerInterface
{
    public function getNextMainProcesses(): array;
    public function queueingStart(MainProcess $mainProcess): bool;
    public function queueingStop(MainProcess $mainProcess): bool;
    public function queueingFailed(MainProcess $mainProcess, Exception $e): void;
}