<?php

namespace Crystal\Config;

interface ConfigInterface {
    public function validate(array $config);
    public function getConfigByKey(string $key);
    public function getProcessNames(): array;
    public function getTasks(): array;
}
