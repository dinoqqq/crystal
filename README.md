# Crystal: A queue worker for recurring tasks

Crystal is a queue worker optimized for recurring tasks that need to be processed in an easy manageable way.

## Features 

- A clear overview of which tasks are recurring (in a DB table).
- Graceful shutdown of tasks (needed for tasks that have an unknown running time).
- Manageable system resources; by setting the total number of tasks/processes allowed to be processed simultaneously.
- Configurable dependencies between tasks (order of running).
- Easily dedicate more or less resources to tasks.

## Requirements

- PHP7.2+
- MySQL / MariaDB

## Get Started

### Install via Composer

`composer require dinoqqq/crystal`

### Usage

- Create a [config](CRYSTAL.md#define-the-config).
- Create a [TaskFactory](CRYSTAL.md#the-taskfactory-class), to define tasks.
- Create a [Queuer](CRYSTAL.md#the-queuer-class), to schedule processes.
- Create [cron entries](CRYSTAL.md#cron-entries) to start the system and keep it running.
- Create a [Controller](CRYSTAL.md#the-controller), to catch the cron entries and start the heartbeat processes.

## Development: running tests

To get the tests running follow these steps:

1. Set the database settings in `/config/database.php`
2. Run the migrations in `/migration`
3. Run `composer update`
4. Run `/vendor/bin/phpunit`

Read the extended README [here](CRYSTAL.md).

