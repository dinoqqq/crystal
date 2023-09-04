<?php

namespace Crystal\PriorityStrategy;

use Exception;
use Crystal\Service\CrystalTasksBaseService;

/**
 * This strategy divides all the priorities equally over all tasks.
 * It uses the LR-Hare (Largest remainder, with Hare quota) method to divide the remainders.
 * It also tries to give a minimum of 1 to each, so starvation of a task is impossible.
 */
class DivideTotalValueEquallyPriorityStrategy implements PriorityStrategyInterface, ExtendedPriorityStrategyInterface
{
    private const MANDATORY_KEYS = [
        'tasks',
        'maxExecutionSlots',
    ];

    private const MANDATORY_KEYS_TASKS = [
        'priority',
        'class',
    ];

    private $_config;
    private $_crystalTasksBaseService;

    /**
     * @throws Exception
     */
    public function __construct(
        array $config,
        CrystalTasksBaseService $crystalTasksBaseService
    ) {
        $this->_config = $config;
        $this->_crystalTasksBaseService = $crystalTasksBaseService;

        $this->validate();
    }

    /**
     * @throws Exception
     */
    public function validate(): bool
    {
        foreach (self::MANDATORY_KEYS as $mandatoryKey) {
            $keyNames = explode('.', $mandatoryKey);
            $this->iterateArrayAndCheckKeysExist($this->_config, $keyNames);
        }

        foreach (self::MANDATORY_KEYS_TASKS as $mandatoryKeyTask) {
            $keyNamesTask = explode('.', $mandatoryKeyTask);
            foreach ($this->_config['tasks'] ?? [] as $taskConfig) {
                $this->iterateArrayAndCheckKeysExist($taskConfig, $keyNamesTask);
            }
        }

        if ($this->_config['maxExecutionSlots'] < count($this->_config['tasks'])) {
            throw new Exception('The maxExecutionSlots need to be equal to or higher than the number of tasks');
        }

        return true;
    }

    /**
     * @throws Exception
     */
    private function iterateArrayAndCheckKeysExist(array $array, array $keyNames): void
    {
        foreach ($keyNames as $keyName) {
            if (!array_key_exists($keyName, $array)) {
                throw new Exception('Key not found in array: "' . $keyName . '"');
            }

            $array = $array[$keyName];
        }
    }

    /**
     * @throws Exception
     */
    public function getTaskClassesAndGrantedExecutionSlots(int $availableExecutionSlots): ?array
    {
        if ($availableExecutionSlots < 1) {
            return null;
        }

        $taskClassesAndPriority = $this->getTasks();

        $crystalTasksDbCount = $this->_crystalTasksBaseService->countNextToBeExecutedCrystalTasks(array_column($taskClassesAndPriority, 'class'));
        if ($crystalTasksDbCount === []) {
            return null;
        }

        $taskClassesAndPriority = $this->mergeTaskClassesAndPriorityWithDbCounts($taskClassesAndPriority, $crystalTasksDbCount);
        $taskClassesAndPriority = $this->removeTaskClassesAndPriorityWithNoDbCount($taskClassesAndPriority);
        $taskClassesAndPriority = $this->sortByPriorityHighToLow($taskClassesAndPriority);

        // When there are not enough availableExecutionSlots, compared to number of tasks, we can simply divide them manually
        // TODO: divide this into classes
        if ($this->isSimpleDistributionExecutionSlotsPossible($availableExecutionSlots, $taskClassesAndPriority)) {
            $taskClassesAndPriority = $this->simpleDistributionExecutionSlots($availableExecutionSlots, $taskClassesAndPriority);
        } else {
            $taskClassesAndPriority = $this->iterateArrayAndDistributeExecutionSlots($taskClassesAndPriority, $availableExecutionSlots);
            $taskClassesAndPriority = $this->roundGrantedExecutionSlotsWithLRHareMethod($taskClassesAndPriority);
            $taskClassesAndPriority = $this->avoidStarvation($taskClassesAndPriority);
        }

        $taskClassesAndGrantedExecutionSlots = $this->createTaskClassesAndGrantedExecutionSlots($taskClassesAndPriority);

        $this->validateGrantedExecutionSlots($taskClassesAndGrantedExecutionSlots);
        return $taskClassesAndGrantedExecutionSlots;
    }

    public function getTasks(): array
    {
        return $this->_config['tasks'] ?? [];
    }

    private function mergeTaskClassesAndPriorityWithDbCounts(array $taskClassesAndPriority, array $crystalTasksDbCount): array
    {
        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            foreach ($crystalTasksDbCount as $crystalTaskDbCount) {
                if ($crystalTaskDbCount['class'] === $taskClassAndPriority['class']) {
                    $taskClassesAndPriority[$key]['dbCount'] = (int)$crystalTaskDbCount['dbCount'];
                    continue 2;
                }
            }
        }

        return $taskClassesAndPriority;
    }

    private function removeTaskClassesAndPriorityWithNoDbCount(array $taskClassesAndPriority): array
    {
        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if (array_key_exists('dbCount', $taskClassAndPriority)) {
                continue;
            }
            unset($taskClassesAndPriority[$key]);
        }
        return $taskClassesAndPriority;
    }

    private function sortByPriorityHighToLow(array $taskClassesAndPriority): array
    {
        usort($taskClassesAndPriority, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return 0;
            }
            return ($a['priority'] > $b['priority']) ? -1 : 1;
        });
        return $taskClassesAndPriority;
    }

    private function isSimpleDistributionExecutionSlotsPossible(int $availableExecutionSlots, array $taskClassesAndPriority): bool
    {
        return count($taskClassesAndPriority) >= $availableExecutionSlots;
    }

    /**
     * Simply grant 1 execution slot, from high to low. Remove the lowest without execution slot.
     */
    private function simpleDistributionExecutionSlots(int $availableExecutionSlots, array $taskClassesAndPriority): array
    {
        foreach (array_keys($taskClassesAndPriority) as $key) {
            if ($availableExecutionSlots < 1) {
                unset($taskClassesAndPriority[$key]);
                continue;
            }

            $taskClassesAndPriority[$key]['grantedExecutionSlots'] = 1;
            $availableExecutionSlots--;
        }

        return $taskClassesAndPriority;
    }

    /**
     * The process is as follow:
     *
     * 1. Calculate how many slots a task would get in case there was no dbCount (openExecutionSlots)
     * 2. Assign as many dbCounts to those openExecutionSlots (grantedExecutionSlots).
     * 3. Check if there are still openExecutionSlots that are not assigned.
     * 4. If so, then distribute the openExecutionSlots over the (new) dbCount values (reiterate).
     *
     * Example 1:
     *
     * $availableExecutionSlots = 5
     *
     * 0.
     *
     * Name     | Priority  | dbCount
     * Task1    | 60        | 9
     * Task2    | 40        | 1
     *
     * 1.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots
     * Task1    | 60        | 9         | (60 / 100) * 5 = 3
     * Task2    | 40        | 1         | (40 / 100) * 5 = 2
     *
     * 2.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots    | grantedExecutionSlots | new dbCount
     * Task1    | 60        | 9         | (60 / 100) * 5 = 3    | 3                     | 6
     * Task2    | 40        | 1         | (40 / 100) * 5 = 2    | 1                     | 0
     *
     * 3.
     * Now replace the $availableExecutionSlots with the rest of the openExecutionSlots = (3 + 2 - 3 - 1) 1;
     *
     * 4. (1. again)
     *
     * Name     | Priority  | dbCount   | openExecutionSlots
     * Task1    | 60        | 6         | (60 / 60) * 1 = 1
     * Task2    | 40        | 0         |
     *
     * 4. (2. again)
     *
     * Name     | Priority  | dbCount   | openExecutionSlots    | grantedExecutionSlots | new dbCount
     * Task1    | 60        | 6         | (60 / 60) * 1 = 1     | (3 + 1) 4             | 5
     * Task2    | 40        | 0         |
     *
     * Done.
     *
     *
     *
     * Example 2:
     *
     * $availableExecutionSlots = 2
     *
     * 0.
     *
     * Name     | Priority  | dbCount
     * Task1    | 999       | 9
     * Task2    | 1         | 1
     *
     * 1.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots
     * Task1    | 999       | 9         | (999 / 1000) * 2 = 1998/1000
     * Task2    | 1         | 1         | (1 / 1000) * 2 = 2/1000
     *
     * 2.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots            | grantedExecutionSlots | new dbCount
     * Task1    | 999       | 9         | (999 / 1000) * 2 = 1998/1000  | 1998/1000             | 9 - (1998/1000)
     * Task2    | 1         | 1         | (1 / 1000) * 2 = 2/1000       | 2/1000                | 1 - (2/1000)
     *
     * 3.
     * Now replace the $availableExecutionSlots with the rest of the openExecutionSlots = (2 - 2) 0;
     *
     * Done.
     *
     *
     *
     * Example 3:
     *
     * $availableExecutionSlots = 5
     *
     * 0.
     *
     * Name     | Priority  | dbCount
     * Task1    | 999       | 1
     * Task2    | 1         | 9
     *
     * 1.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots
     * Task1    | 999       | 1         | (999 / 1000) * 5 = 4995/1000
     * Task2    | 1         | 9         | (1 / 1000) * 5 = 5/1000
     *
     * 2.
     *
     * Name     | Priority  | dbCount   | openExecutionSlots            | grantedExecutionSlots | new dbCount
     * Task1    | 999       | 1         | (999 / 1000) * 5 = 4995/1000  | 1                     | 0
     * Task2    | 1         | 9         | (1 / 1000) * 5 = 5/1000       | 5/1000                | 9 - (5/1000)
     *
     * 3.
     * Now replace the $availableExecutionSlots with the rest of the openExecutionSlots = (3995/1000 + 0);
     *
     * 4. (1. again)
     *
     * Name     | Priority  | dbCount       | openExecutionSlots                | grantedExecutionSlots     | new dbCount
     * Task1    | 999       | 0             |                                   | 1
     * Task2    | 1         | 9 - (5/1000)  | (1 / 1) * 3995/1000 = 3995/1000   | 5/1000 + 3995/1000 = 4    | 5
     *
     * Done.
     *
     * @throws Exception
     */
    private function iterateArrayAndDistributeExecutionSlots(array $taskClassesAndPriority, float $availableExecutionSlots, int $maxDepth = 100): array
    {
        $maxDepth--;

        if ($maxDepth < 1) {
            throw new Exception('Recursion went too deep for function iterateArrayAndDistributeExecutionSlots');
        }

        $taskClassesAndPriority = $this->addOpenExecutionSlots($taskClassesAndPriority, $availableExecutionSlots);
        $taskClassesAndPriority = $this->grantExecutionSlots($taskClassesAndPriority);

        if ($this->hasOpenExecutionSlotsAndDbCounts($taskClassesAndPriority)) {
            // The open execution slots, function as the new max execution slots
            $availableExecutionSlots = $this->getSumOpenExecutionSlots($taskClassesAndPriority);
            // Make sure to reset the ones that are done, so they are ignored the next time in hasOpenExecutionSlotsAndDbCounts
            $taskClassesAndPriority = $this->resetOpenExecutionSlots($taskClassesAndPriority);
            return $this->iterateArrayAndDistributeExecutionSlots($taskClassesAndPriority, $availableExecutionSlots, $maxDepth);
        }

        return $taskClassesAndPriority;
    }

    /**
     * @throws Exception
     */
    private function addOpenExecutionSlots(array $taskClassesAndPriority, float $availableExecutionSlots): array
    {
        $sumPriorities = $this->getSumPrioritiesWhereGrantingExecutionSlotsIsNotDone($taskClassesAndPriority);

        if ($sumPriorities === 0) {
            throw new Exception('Sum priorities seems to be 0');
        }

        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if ($this->isGrantingExecutionSlotsDone($taskClassAndPriority)) {
                continue;
            }

            $priorityPercentage = $taskClassAndPriority['priority'] / $sumPriorities;
            // overwrite any existing
            $taskClassesAndPriority[$key]['openExecutionSlots'] =  $availableExecutionSlots * $priorityPercentage;
        }

        return $taskClassesAndPriority;
    }

    private function getSumPrioritiesWhereGrantingExecutionSlotsIsNotDone(array $taskClassesAndPriority): int
    {
        $sum = 0;
        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            if ($this->isGrantingExecutionSlotsDone($taskClassAndPriority)) {
                continue;
            }
            $sum += $taskClassAndPriority['priority'];
        }
        return $sum;
    }

    private function grantExecutionSlots(array $taskClassesAndPriority): array
    {
        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if ($this->isGrantingExecutionSlotsDone($taskClassAndPriority)) {
                continue;
            }

            if (!array_key_exists('grantedExecutionSlots', $taskClassAndPriority)) {
                $taskClassesAndPriority[$key]['grantedExecutionSlots'] = 0.0;
            }

            if ($taskClassesAndPriority[$key]['dbCount'] >= $taskClassesAndPriority[$key]['openExecutionSlots']) {
                $taskClassesAndPriority[$key]['grantedExecutionSlots'] += $taskClassesAndPriority[$key]['openExecutionSlots'];
                $taskClassesAndPriority[$key]['dbCount'] -= $taskClassesAndPriority[$key]['openExecutionSlots'];
                $taskClassesAndPriority[$key]['openExecutionSlots'] = 0;
            }

            if ($taskClassesAndPriority[$key]['dbCount'] < $taskClassesAndPriority[$key]['openExecutionSlots']) {
                $taskClassesAndPriority[$key]['grantedExecutionSlots'] += $taskClassesAndPriority[$key]['dbCount'];
                $taskClassesAndPriority[$key]['openExecutionSlots'] -= $taskClassesAndPriority[$key]['dbCount'];
                $taskClassesAndPriority[$key]['dbCount'] = 0;

                $taskClassesAndPriority[$key] = $this->setGrantingExecutionSlotsDone($taskClassesAndPriority[$key]);
            }
        }

        return $taskClassesAndPriority;
    }

    private function hasOpenExecutionSlotsAndDbCounts(array $taskClassesAndPriority): bool
    {
        $openExecutionSlots = 0;
        $dbCount = 0;
        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            $openExecutionSlots += $taskClassAndPriority['openExecutionSlots'];
            $dbCount += $taskClassAndPriority['dbCount'];
        }

        return $openExecutionSlots && $dbCount;
    }

    private function getSumOpenExecutionSlots(array $taskClassesAndPriority): float
    {
        $openExecutionSlots = 0;
        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            $openExecutionSlots += $taskClassAndPriority['openExecutionSlots'];
        }

        return $openExecutionSlots;
    }

    private function resetOpenExecutionSlots(array $taskClassesAndPriority): array
    {
        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if ($this->isGrantingExecutionSlotsDone($taskClassAndPriority)) {
                $taskClassesAndPriority[$key]['openExecutionSlots'] = 0;
            }
        }

        return $taskClassesAndPriority;
    }
    /**
     * Round the remainders using the Largest Remainder Method, with the Hare Quota.
     */
    private function roundGrantedExecutionSlotsWithLRHareMethod(array $taskClassesAndPriority): array
    {
        $taskClassesAndPriority = $this->sortByRemainderGrantedExecutionSlotsHighToLow($taskClassesAndPriority);
        $sumRemaindersGrantedExecutionSlots = $this->getSumRemaindersGrantedExecutionSlots($taskClassesAndPriority);

        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if ($sumRemaindersGrantedExecutionSlots < 1) {
                $taskClassesAndPriority[$key]['grantedExecutionSlots'] = (int)floor($taskClassAndPriority['grantedExecutionSlots']);
                continue;
            }

            $taskClassesAndPriority[$key]['grantedExecutionSlots'] = (int)ceil($taskClassAndPriority['grantedExecutionSlots']);
            $sumRemaindersGrantedExecutionSlots--;
        }
        return $taskClassesAndPriority;
    }

    private function sortByRemainderGrantedExecutionSlotsHighToLow(array $taskClassesAndPriority): array
    {
        usort($taskClassesAndPriority, function ($a, $b) {
            $remainderA = (($a['grantedExecutionSlots'] * 100) % 100);
            $remainderB = (($b['grantedExecutionSlots'] * 100) % 100);
            if ($remainderA === $remainderB) {
                return 0;
            }
            return ($remainderA > $remainderB) ? -1 : 1;
        });
        return $taskClassesAndPriority;
    }

    /**
     * Get the sum of all the remainders. Since it should be near a real number, we round up differences and return an int.
     */
    private function getSumRemaindersGrantedExecutionSlots(array $taskClassesAndPriority): int
    {
        $sumRemainders = 0;

        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            $sumRemainders += (($taskClassAndPriority['grantedExecutionSlots'] * 10000) % 10000) / 10000;
        }

        // Check if we have a rounding case like "x.9999999", in that case, round up.

        if ((int) ($sumRemainders * 10000) % 10000 === 9999) {
            $sumRemainders = ceil($sumRemainders);
        }

        return (int)$sumRemainders;
    }

    /**
     * Avoid starvation, by making sure every task has at least 1 slot, no matter the priority
     *
     * @throws Exception
     */
    private function avoidStarvation(array $taskClassesAndPriority): array
    {
        $slotsToBeDistributed = $this->countGrantedExecutionSlotsZero($taskClassesAndPriority);
        $taskClassesAndPriority = $this->redistributeHighestGrantedExecutionSlots($taskClassesAndPriority, $slotsToBeDistributed);
        return $this->redistributeLowestGrantedExecutionSlots($taskClassesAndPriority);
    }

    private function countGrantedExecutionSlotsZero(array $taskClassesAndPriority): int
    {
        $count = 0;
        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            if (
                $taskClassAndPriority['grantedExecutionSlots'] === 0
                || $taskClassAndPriority['grantedExecutionSlots'] === 0.0
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Redistribution goes as follow:
     *
     * slotsToBeDistributed = 7 (equals slots who equal 0)
     *
     * grantedExecutionSlots:
     *
     * 86660000000
     * 76660000000
     * 66660000000
     * 66650000000
     * 66540000000
     * 65440000000
     *
     * @throws Exception
     */
    private function redistributeHighestGrantedExecutionSlots(array $taskClassesAndPriority, int $slotsToBeDistributed, int $maxDepth = 1000): array
    {
        $maxDepth--;

        if ($maxDepth < 1) {
            throw new Exception('Recursion went too deep for function redistributeHighestGrantedExecutionSlots');
        }

        $taskClassesAndPriority = $this->sortByGrantedExecutionSlotsHighToLow($taskClassesAndPriority);

        if ($slotsToBeDistributed === 0) {
            return $taskClassesAndPriority;
        }

        $index = 0;
        while ($slotsToBeDistributed > 0) {
            if ($this->isLowestRelevantGrantedExecutionSlot($taskClassesAndPriority, $index)) {
                if ($this->equalsPreviousGrantedExecutionSlot($taskClassesAndPriority, $index) || $this->isFirstGrantedExecutionSlot($index)) {
                    $taskClassesAndPriority[$index]['grantedExecutionSlots']--;
                    $slotsToBeDistributed--;
                }

                // try again and start from the first task again
                return $this->redistributeHighestGrantedExecutionSlots($taskClassesAndPriority, $slotsToBeDistributed, $maxDepth);
            }

            if ($this->greaterThanNextGrantedExecutionSlot($taskClassesAndPriority, $index)) {
                $taskClassesAndPriority[$index]['grantedExecutionSlots']--;
                $slotsToBeDistributed--;
                // try again on the same task
                continue;
            }

            // go to the next task
            $index++;
        }

        return $taskClassesAndPriority;
    }

    private function isLowestRelevantGrantedExecutionSlot(array $taskClassesAndPriority, int $index): bool
    {
        return !isset($taskClassesAndPriority[$index + 1])
            || $taskClassesAndPriority[$index + 1]['grantedExecutionSlots'] === 0
            || $taskClassesAndPriority[$index + 1]['grantedExecutionSlots'] === 0.0
            || $taskClassesAndPriority[$index + 1]['grantedExecutionSlots'] === 1
            || $taskClassesAndPriority[$index + 1]['grantedExecutionSlots'] === 1.0;
    }

    private function equalsPreviousGrantedExecutionSlot(array $taskClassesAndPriority, int $index): bool
    {
        return isset($taskClassesAndPriority[$index - 1])
            && (int)$taskClassesAndPriority[$index] === (int)$taskClassesAndPriority[$index - 1];
    }

    private function isFirstGrantedExecutionSlot(int $index): bool
    {
        return $index === 0;
    }

    private function greaterThanNextGrantedExecutionSlot(array $taskClassesAndPriority, int $index): bool
    {
        return isset($taskClassesAndPriority[$index + 1])
            && $taskClassesAndPriority[$index]['grantedExecutionSlots'] > $taskClassesAndPriority[$index + 1]['grantedExecutionSlots'];
    }

    /**
     * Simply increase all the 0's by 1. Redistribution goes as follow:
     *
     * grantedExecutionSlots:
     *
     * 86660000000
     * 86661111111
     */
    private function redistributeLowestGrantedExecutionSlots(array $taskClassesAndPriority): array
    {
        foreach ($taskClassesAndPriority as $key => $taskClassAndPriority) {
            if (
                $taskClassAndPriority['grantedExecutionSlots'] === 0
                || $taskClassAndPriority['grantedExecutionSlots'] === 0.0
            ) {
                $taskClassesAndPriority[$key]['grantedExecutionSlots'] = 1;
            }
        }

        return $taskClassesAndPriority;
    }

    private function sortByGrantedExecutionSlotsHighToLow(array $taskClassesAndPriority): array
    {
        usort($taskClassesAndPriority, function ($a, $b) {
            if ($a['grantedExecutionSlots'] === $b['grantedExecutionSlots']) {
                return 0;
            }
            return ($a['grantedExecutionSlots'] > $b['grantedExecutionSlots']) ? -1 : 1;
        });
        return $taskClassesAndPriority;
    }

    private function createTaskClassesAndGrantedExecutionSlots(array $taskClassesAndPriority): array
    {
        $taskClassesAndGrantedExecutionSlots = [];
        foreach ($taskClassesAndPriority as $taskClassAndPriority) {
            $taskClassesAndGrantedExecutionSlots[] = [
                'class' => $taskClassAndPriority['class'],
                'grantedExecutionSlots' => $taskClassAndPriority['grantedExecutionSlots'],
            ];
        }

        return $taskClassesAndGrantedExecutionSlots;
    }

    /**
     * @throws Exception
     */
    private function validateGrantedExecutionSlots(array $taskClassesAndGrantedExecutionSlots): void
    {
        foreach ($taskClassesAndGrantedExecutionSlots as $taskClassAndGrantedExecutionSlots) {
            if ($taskClassAndGrantedExecutionSlots['grantedExecutionSlots'] < 0) {
                throw new Exception('grantedExecutionSlot is < 0, weird');
            }

            if (
                !is_int($taskClassAndGrantedExecutionSlots['grantedExecutionSlots'])
                && !is_float($taskClassAndGrantedExecutionSlots['grantedExecutionSlots'])
            ) {
                throw new Exception('grantedExecutionSlot is not an int or float, weird');
            }
        }
    }


    private function isGrantingExecutionSlotsDone(array $taskClassAndPriority): bool
    {
        return isset($taskClassAndPriority['done']);
    }

    private function setGrantingExecutionSlotsDone(array $taskClassAndPriority): array
    {
        $taskClassAndPriority['done'] = true;
        return $taskClassAndPriority;
    }
}
