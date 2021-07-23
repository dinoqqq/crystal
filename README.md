# Crystal: A queue worker for recurring tasks

Crystal is a queue worker optimized for recurring tasks that need to be processed in an easy manageable way.

- A clear overview of which tasks are recurring (in a DB table)
- Graceful shutdown of tasks (needed for tasks that have an unknown running time)
- Manageable system resources; by setting the total number of tasks/processes you allow to be processed simultaneously
- Configurable dependencies between tasks (order of running)
- Easily dedicate more or less resources to tasks

## Requirements

- PHP7.2+
- MySQL / MariaDB

## Get Started

### Install via Composer

`composer require dinoqqq/crystal`

## Running tests

To get the tests running follow these steps:

1. Set the database settings in `/config/database.php`
2. Run the migrations in `/migration`
3. Run `composer install`
4. Run `/vendor/bin/phpunit`
