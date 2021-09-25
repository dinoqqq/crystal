<?php

namespace Crystal\Config;

interface ConfigInterface
{
    public function validate();
    public function getConfigByKey(string $key);
    public function getMainProcessNames(): array;
    public function getTasks(): array;
}
