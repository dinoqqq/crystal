<?php

namespace Crystal\Heartbeat;

interface HeartbeatFactoryInterface
{
    public function create(string $type): HeartbeatInterface;
}
