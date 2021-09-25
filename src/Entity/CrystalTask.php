<?php

namespace Crystal\Entity;

use DateInterval;
use Exception;
use DateTime;
use Crystal\Exception\CrystalTaskStateErrorException;

/**
     * The crystal tasks can be represented as a finite state machine.
     *
     * - A task can only have 1 of the following states.
     * - Ideally only 1 process can/may take care of a state change
     * - Only the dead state is taken automatically, by the passing of time.
     * - Multiple different ingoing processes in a state is fine
     * - Multiple different outgoing processes out of a state is not fine (race conditions) (NEW state exception)
     * - State (RUNNING) DEAD is virtual (only exists in code), the DB state will be RUNNING.
     *
     * To make sure that the core heartbeat processes are not overlapping in state changes (which could cause data
     * inconsistency) we delegate the functions to the core processes (EXECUTE, RESCHEDULE, QUEUE).
     *
     *             QUEUE()
     *             ┌─────┐
     *             │     │
     *         ┌───▼─────────┐            ┌─────────────┐    ┌─────────────┐
     * QUEUE() │             │  EXECUTE() │             │TIME│(DB: RUNNING)│
     * ───────►│     NEW     ├───────────►│   RUNNING   ├───►│    DEAD     ├──────┐
     *         │             │            │             │    │             │      │
     *         └───────▲───▲─┘            └──┬──────────┘    └─────────────┘      │
     *                 │   │                 │                                    │
     *                 │   │                 │                                    │
     *                 │   │                 │               ┌─────────────┐      │
     *                 │   │                 │   EXECUTE()   │             │      │
     *                 │   │                 └──────────────►│NOT_COMPLETED├──────┐
     *                 │   │                 │               │             │      │
     *                 │   │                 │               └─────────────┘      │
     *                 │   │                 │                                    │
     *                 │   │                 │               ┌─────────────┐      │
     *                 │   │                 │   EXECUTE()   │             │      │
     *                 │   │                 └──────────────►│    ERROR    ├───┐  │
     *                 │   │                 │               │             │   │  │
     *                 │   │                 │               └─────────────┘   │  │
     *                 │   │                 │                                 │  │
     *                 │   │                 │               ┌─────────────┐   │  │
     *                 │   │                 │   EXECUTE()   │             │   │  │
     *                 │   │                 └──────────────►│  COMPLETED  │───┐  │
     *                 │   │                                 │             │   │  │
     *                 │   │                                 └─────────────┘   │  │
     *                 │   │           QUEUE()                                 │  │
     *                 │   └───────────────────────────────────────────────────┘  │
     *                 │             RESCHEDULE()                                 │
     *                 └──────────────────────────────────────────────────────────┘
     *
     * Functions for state changes:
     *
     * EXECUTE()
     * stateNewToRunning();
     * stateRunningToError();
     * stateRunningToCompleted();
     * stateRunningToNotCompleted();
     *
     * RESCHEDULE()
     * stateDeadToNew();
     * stateNotCompletedToNew();
     *
     * QUEUE()
     * stateErrorToNew();
     * stateCompletedToNew();
     * stateNewToNew();
     * (nothing to NEW)
     *
     * Notes:
     * - If task needs to be QUEUED, but is DEAD, it will be RESCHEDULED automatically
     * - If task needs to be QUEUED, but is NOT_COMPLETED, it will be RESCHEDULED automatically
     * - If task needs to be QUEUED, but is RUNNING:
     * 1. When task is NOT depend_on, FAIL
     * 2. When task is depend_on another task, SKIP
     */

class CrystalTask implements EntityInterface
{
    public $id;
    public $class;
    public $entity_uid;
    public $timeout;
    public $cooldown;
    public $range;
    public $date_start;
    public $date_end;
    public $state;
    public $error_tries;
    public $date_created;

    /**
     * A task is considered a duplicate when all these columns are the same
     */
    public const UNIQUE_INDEX_CRYSTAL_TASK = [
        'class',
        'entity_uid',
        'range',
    ];

    /**
     * These values can vary for the same task.
     * This is used to check if an object is dirty
     *
     * Note: state is special and left out
     * Note: date_created is special and left out
     */
    private const FREE_FIELDS_CRYSTAL_TASK = [
        'timeout',
        'cooldown',
        'date_start',
        'date_end',
        'error_tries',
    ];

    public const STATE_CRYSTAL_TASK_NEW = 'new';
    public const STATE_CRYSTAL_TASK_RUNNING = 'running';
    public const STATE_CRYSTAL_TASK_ERROR = 'error';
    public const STATE_CRYSTAL_TASK_COMPLETED = 'completed';
    public const STATE_CRYSTAL_TASK_NOT_COMPLETED = 'not_completed';
    /* Exists only in code (virtual), since we don't explicitly set this state, because then we reschedule immediately */
    public const STATE_CRYSTAL_TASK_DEAD = 'dead';

    /* Give the state change to dead a bit more time, so processes won't overlap that easy */
    public const STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN = 2;

    /* Let's reschedule with a certain cooldown period as well */
    public const STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN = 2;

    public function __construct(array $data = [])
    {
        if (!empty($data)) {
            $this->exchangeArray($data);
        }
    }

    private function exchangeArray(array $data)
    {
        $this->id = (empty($data['id'])) ? null : $data['id'];
        $this->class = (empty($data['class'])) ? null : $data['class'];
        $this->entity_uid = (empty($data['entity_uid'])) ? null : $data['entity_uid'];
        $this->timeout = (empty($data['timeout'])) ? null : $data['timeout'];
        $this->cooldown = (empty($data['cooldown'])) ? null : $data['cooldown'];
        $this->range = $data['range'] ?? '';
        $this->date_start = (empty($data['date_start'])) ? null : $data['date_start'];
        $this->date_end = (empty($data['date_end'])) ? null : $data['date_end'];
        $this->state = $data['state'] ?? self::STATE_CRYSTAL_TASK_NEW;
        $this->error_tries = $data['error_tries'] ?? 0;
        $this->date_created = (empty($data['date_created'])) ? (new DateTime())->format('Y-m-d H:i:s') : $data['date_created'];
    }

    /**
     * @throws Exception
     */
    public function getState(): string
    {
        if ($this->isStateCrystalTaskNew()) {
            return self::STATE_CRYSTAL_TASK_NEW;
        }

        if ($this->isStateCrystalTaskRunning()) {
            return self::STATE_CRYSTAL_TASK_RUNNING;
        }

        if ($this->isStateCrystalTaskDead()) {
            return self::STATE_CRYSTAL_TASK_DEAD;
        }

        if ($this->isStateCrystalTaskError()) {
            return self::STATE_CRYSTAL_TASK_ERROR;
        }

        if ($this->isStateCrystalTaskCompleted()) {
            return self::STATE_CRYSTAL_TASK_COMPLETED;
        }

        if ($this->isStateCrystalTaskNotCompleted()) {
            return self::STATE_CRYSTAL_TASK_NOT_COMPLETED;
        }

        throw new CrystalTaskStateErrorException('Could not determine state, weird');
    }

    public function isStateCrystalTaskNew(): bool
    {
        return $this->state === self::STATE_CRYSTAL_TASK_NEW;
    }

    /**
     * @throws Exception
     */
    public function isStateCrystalTaskRunning(): bool
    {
        $dateStart = (new Datetime())->createFromFormat('Y-m-d H:i:s', $this->date_start);
        $dateNow = (new Datetime());
        $period = $this->timeout + $this->cooldown + self::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN;
        return !is_null($this->date_start)
            && is_null($this->date_end)
            && $this->state === self::STATE_CRYSTAL_TASK_RUNNING
            && $dateNow->sub(new DateInterval('PT' . $period . 'S')) < $dateStart;
    }

    /**
     * @throws Exception
     */
    public function isStateCrystalTaskDead(): bool
    {
        $dateStart = (new Datetime())->createFromFormat('Y-m-d H:i:s', $this->date_start);
        $dateNow = (new Datetime());
        $period = $this->timeout + $this->cooldown + self::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN;
        return !is_null($this->date_start)
            && is_null($this->date_end)
            && $this->state === self::STATE_CRYSTAL_TASK_RUNNING
            && $dateNow->sub(new DateInterval('PT' . $period . 'S')) >= $dateStart;
    }

    public function isStateCrystalTaskError(): bool
    {
        return $this->state === self::STATE_CRYSTAL_TASK_ERROR;
    }

    public function isStateCrystalTaskCompleted(): bool
    {
        return $this->state === self::STATE_CRYSTAL_TASK_COMPLETED;
    }

    public function isStateCrystalTaskNotCompleted(): bool
    {
        return $this->state === self::STATE_CRYSTAL_TASK_NOT_COMPLETED;
    }

    /**
     * Should take into account the RUNNING_TO_DEAD cooldown and the RESCHEDULE cooldown.
     *
     * @throws Exception
     */
    public function isStateCrystalTaskDeadAndAfterRescheduleCooldown(): bool
    {
        $dateStart = (new Datetime())->createFromFormat('Y-m-d H:i:s', $this->date_start);
        $dateNow = (new Datetime());
        $period = $this->timeout + $this->cooldown + self::STATE_CRYSTAL_TASK_RUNNING_TO_DEAD_COOLDOWN + self::STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN;
        return !is_null($this->date_start)
            && is_null($this->date_end)
            && $this->state === self::STATE_CRYSTAL_TASK_RUNNING
            && $dateNow->sub(new DateInterval('PT' . $period . 'S')) >= $dateStart;
    }

    /**
     * Should take into account the RESCHEDULE cooldown.
     *
     * @throws Exception
     */
    public function isStateCrystalTaskNotCompletedAndAfterRescheduleCooldown(): bool
    {
        $dateEnd = (new Datetime())->createFromFormat('Y-m-d H:i:s', $this->date_end);
        $dateNow = (new Datetime());
        $period = self::STATE_CRYSTAL_TASK_RESCHEDULE_COOLDOWN;
        return !is_null($this->date_start)
            && !is_null($this->date_end)
            && $this->state === self::STATE_CRYSTAL_TASK_NOT_COMPLETED
            && $dateNow->sub(new DateInterval('PT' . $period . 'S')) >= $dateEnd;
    }


    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateNewToRunning(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_NEW) {
            throw new CrystalTaskStateErrorException('Trying to set state NEW to RUNNING failed, weird');
        }

        $this->date_start = (new DateTime())->format('Y-m-d H:i:s');
        $this->state = self::STATE_CRYSTAL_TASK_RUNNING;
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateRunningToError(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_RUNNING) {
            throw new CrystalTaskStateErrorException('Trying to set state RUNNING to ERROR failed, weird');
        }

        $this->date_end = (new DateTime())->format('Y-m-d H:i:s');
        $this->state = self::STATE_CRYSTAL_TASK_ERROR;
    }


    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateRunningToCompleted(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_RUNNING) {
            throw new CrystalTaskStateErrorException('Trying to set state RUNNING to COMPLETED failed, weird');
        }

        $this->date_end = (new DateTime())->format('Y-m-d H:i:s');
        $this->state = self::STATE_CRYSTAL_TASK_COMPLETED;
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateRunningToNotCompleted(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_RUNNING) {
            throw new CrystalTaskStateErrorException('Trying to set state RUNNING to NOT_COMPLETED failed, weird');
        }

        $this->date_end = (new DateTime())->format('Y-m-d H:i:s');
        $this->state = self::STATE_CRYSTAL_TASK_NOT_COMPLETED;
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateErrorToNew(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_ERROR) {
            throw new CrystalTaskStateErrorException('Trying to set state ERROR to NEW failed, weird');
        }

        $this->date_start = null;
        $this->date_end = null;
        $this->state = self::STATE_CRYSTAL_TASK_NEW;
        $this->error_tries = 0;
        $this->date_created = (new DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateDeadToNew(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_DEAD) {
            throw new CrystalTaskStateErrorException('Trying to set state DEAD to NEW failed, weird');
        }

        $this->date_start = null;
        $this->date_end = null;
        $this->state = self::STATE_CRYSTAL_TASK_NEW;
        $this->date_created = (new DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateCompletedToNew(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_COMPLETED) {
            throw new CrystalTaskStateErrorException('Trying to set state COMPLETED to NEW failed, weird');
        }

        $this->date_start = null;
        $this->date_end = null;
        $this->state = self::STATE_CRYSTAL_TASK_NEW;
        $this->error_tries = 0;
        $this->date_created = (new DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateNotCompletedToNew(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_NOT_COMPLETED) {
            throw new CrystalTaskStateErrorException('Trying to set state NOT_COMPLETED to NEW failed, weird');
        }
        $this->date_start = null;
        $this->date_end = null;
        $this->state = self::STATE_CRYSTAL_TASK_NEW;
        $this->date_created = (new DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @throws Exception
     * @throws CrystalTaskStateErrorException
     */
    public function stateNewToNew(): void
    {
        if ($this->getState() !== self::STATE_CRYSTAL_TASK_NEW) {
            throw new CrystalTaskStateErrorException('Trying to set state NEW to NEW failed, weird');
        }
        $this->date_created = (new DateTime())->format('Y-m-d H:i:s');
    }

    /**
     * @throws Exception
     */
    public function forceStateError(): void
    {
        $this->state = self::STATE_CRYSTAL_TASK_ERROR;
    }

    public function increaseErrorTries(): void
    {
        $this->error_tries++;
    }

    /**
     * Copy the FREE FIELDS from 1 crystal task to another
     */
    public function copyNewToExistingTaskForSave(CrystalTask $crystalTaskNew): void
    {
        foreach (self::FREE_FIELDS_CRYSTAL_TASK as $field) {
            $this->$field = $crystalTaskNew->$field;
        }
        // Also copy the date_created on the new task
        $this->date_created = $crystalTaskNew->date_created;
    }


    /**
     * Check if an object is dirty
     */
    public function isDirty(CrystalTask $crystalTaskToCompare): bool
    {
        foreach (self::FREE_FIELDS_CRYSTAL_TASK as $field) {
            if ($this->$field !== $crystalTaskToCompare->$field) {
                return true;
            }
        }

        return false;
    }
}
