<?php

namespace Crystal\Test\Core;

use Crystal\Database\Database;
use Exception;
use ReflectionClass;
use ReflectionException;

abstract class BaseTestApp extends BaseTest
{
    public const ROOT_PATH = __DIR__ . '/../../';
    public const GLOBAL_CONFIG_PATH = self::ROOT_PATH . 'config/global.php';
    public const SINGLE_TASK_CONFIG_PATH = self::ROOT_PATH . 'config/single_task.php';
    public const DATABASE_CONFIG_PATH = self::ROOT_PATH . 'config/database.php';

    private static $_database = null;
    public $database = null;

    /**
     * @throws Exception
     */
    protected function setUp()
    {
        if (is_null(self::$_database)) {
            $config = self::getDatabaseConfig();
            self::$_database = Database::getInstance($config);
        }
        $this->database = self::$_database;
    }

    protected function tearDown()
    {
    }

    public static function getDatabaseConfig(): array
    {
        return require self::DATABASE_CONFIG_PATH;
    }

    public static function getGlobalConfig(): array
    {
        return require self::GLOBAL_CONFIG_PATH;
    }

    public static function getSingleTaskConfig(): array
    {
        return require self::SINGLE_TASK_CONFIG_PATH;
    }

    /**
     * @throws ReflectionException
     */
    public function invokeMethod($object, $methodName, array $parameters = array())
    {
        $reflection = new ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function truncate(array $tableNames)
    {
        foreach($tableNames as $tableName) {
            $this->database->query('TRUNCATE <' . $tableName . '>;');
        }
    }
}
