<?php

namespace Crystal\Heartbeat;

interface HeartbeatInterface
{
    public function heartbeat(): bool;
}
