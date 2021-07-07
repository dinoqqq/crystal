<?php

namespace Crystal\Database;

use Exception;
use Medoo\Medoo;

class Database
{

    private static $_instance = null;

    private const ALLOWED_TYPES = ['mysql'];

    /**
     * @throws Exception
     */
    public function __construct()
    {
        throw new Exception('Cannot call constructor');
    }

    /**
     * @throws Exception
     */
    public static function getInstance(array $config): Medoo
    {
        if (self::$_instance) {
            return self::$_instance;
        }

        self::validate($config);

        return self::createInstance($config);
    }

    private static function createInstance(array $config): Medoo
    {
        self::$_instance = new Medoo([
            'database_type' => $config['database_type'],
            'server' => $config['server'],
            'database_name' => $config['database_name'],
            'username' => $config['username'],
            'password' => $config['password'],

            'charset' => $config['charset'],
            'collation' => $config['collation'],
            'port' => $config['port'],
        ]);
        return self::$_instance;
    }

    /**
     * @throws Exception
     */
    private static function validate(array $config): void
    {
        if (!in_array($config['database_type'] ?? '', self::ALLOWED_TYPES)) {
            throw new Exception('Database type not allowed, only: ' . implode(',', self::ALLOWED_TYPES));
        }
    }
}