<?php

namespace Application;

class StatisticHelper
{
    //Часи в хвилинах, починаючи з 00:00
    const DAY_START                 = 540;//9:00
    const DAY_END                   = 1080;//18:00
    const DINNER_TIME               = 780;//13:00

    //Коефіцієнт, який використовується для визначення мінімального
    //часу, який працівник сидить за комп'ютером
    const MIN_WORK_INTERVAL_RATE    = 0.2;

    //Максимальний час, який працівник сидить за комп'ютером, хв
    const MAX_WORK_INTERVAL         = 120;

    //Мінімальний час, який працівник сидить за комп'ютером, хв
    const MIN_WORK_INTERVAL         = 15;

    //Коефіцієнт, який визначає максимальний (фокус-фактор = 0) сумарний час відсутності
    //статистики відносно повного робочого дня
    const MAX_IDLE_INTERVAL_RATE    = 0.5;//4год

    //Відсоток максимального (продуктивність = 100) робочого часу, який займають
    //"ефективні" програми/сайти
    const MAX_EFFICIENT_RATE        = 0.8;//80%

    //Відсоток мінімально допустимого (продуктивність = 0) робочого часу, який займають
    //"ефективні" програми/сайти
    const MIN_EFFICIENT_RATE        = 0.4;//40%

    //Мінімальний, та максимальний час, який користувач може провести з однією програмою
    const MAX_TIME_WITH_APP         = 10;
    const MIN_TIME_WITH_APP         = 2;

    /**
     * Генерація статистики з заданими параметрами на один день
     *
     * @param int $focusFactor
     * @param int $efficiency
     * @param array $mainApps
     * @param array $otherApps
     * @return array
     */
    public static function makeStatistic($focusFactor, $efficiency, $mainApps, $otherApps)
    {
        $focusFactor = self::_prepareFocusFactor($focusFactor);
        $dayTime = self::DAY_END - self::DAY_START - 60;
        //Максимальний час, який може "висидіти" працівник за комп'ютером
        //0 = 15, 100 = 120 (2 год)
        $maxWorkInterval = (self::MAX_WORK_INTERVAL - self::MIN_WORK_INTERVAL)
            * $focusFactor / 100 + self::MIN_WORK_INTERVAL;
        //Мінімальний час, який працівник сидить за комп'ютером
        $minWorkInterval = $maxWorkInterval * self::MIN_WORK_INTERVAL_RATE;
        //Сумарний час пробілів в статистиці за день
        $idleTime = self::MAX_IDLE_INTERVAL_RATE * $dayTime
            * (100 - $focusFactor) / 100;
        //Час, який працівник провів за комп'ютером
        $workTime = $dayTime - $idleTime;
        //Генерація відрізків робочого часу
        $workIntervals = self::_generateIntervals($minWorkInterval, $maxWorkInterval, $workTime);
        //Генерація відрізків простою
        $count = count($workIntervals);
        $idleIntervals = array();
        if ($idleTime) {
            $idleIntervals = self::_generateIntervals(
                $minWorkInterval * $idleTime / $workTime,
                $maxWorkInterval * $idleTime / $workTime,
                $idleTime,
                $count - 1
            );
        }
        $effectiveTime = $workTime *
            (self::MIN_EFFICIENT_RATE +
                (self::MAX_EFFICIENT_RATE - self::MIN_EFFICIENT_RATE) * $efficiency / 10
            );
        $ineffectiveTime = $workTime - $effectiveTime;
        $effectiveIntervals = self::_generateIntervals(
            self::MIN_TIME_WITH_APP,
            self::MAX_TIME_WITH_APP,
            $effectiveTime
        );
        foreach ($effectiveIntervals as &$interval) {
            $interval = array(
                'time'  => $interval,
                'app'   => $mainApps[array_rand($mainApps)],
            );
        }
        $ineffectiveIntervals = array();
        if (!empty($otherApps)) {
            $ineffectiveIntervals = self::_generateIntervals(
                self::MIN_TIME_WITH_APP,
                self::MAX_TIME_WITH_APP,
                $ineffectiveTime
            );
            foreach ($ineffectiveIntervals as &$interval) {
                $interval = array(
                    'time' => $interval,
                    'app' => $otherApps[array_rand($otherApps)],
                );
            }
        }
        return self::_mergeIntervals($workIntervals, $idleIntervals, $effectiveIntervals, $ineffectiveIntervals);
    }

    protected static function _prepareFocusFactor($ff)
    {
        $k = 1;
        $x = $ff / 100 * $k;
        $y = 1 / ($x + 0.5) - 1 / ($k + 0.5);
        $ff = 100 - ceil($y / (1 / 0.5 - 1 / ($k + 0.5)) * 100);
        return $ff;
    }

    protected static function _mergeIntervals($workIntervals, $idleIntervals, $effective, $ineffective)
    {
        $partsOfWork = array_merge($effective, $ineffective);
        shuffle($partsOfWork);
        $result = array();
        $currentTime = self::DAY_START;
        while ($wi = array_shift($workIntervals)) {
            $result = array_merge($result, self::_fillInterval($currentTime, $wi, $partsOfWork));
            if ($ii = array_shift($idleIntervals)) {
                $result[] = array(
                    'time'  => $ii,
                    'app'   => null,
                );
            }
        }
        return self::_insertDinner($result);
    }

    protected static function _insertDinner($statistic)
    {
        $result = array();
        $currTime = self::DAY_START;
        while ($node = array_shift($statistic)) {
            if ($node['time'] + $currTime < self::DINNER_TIME) {
                $result[] = $node;
                $currTime += $node['time'];
            } else {
                $prevNode = array(
                    'time'  => self::DINNER_TIME - $currTime,
                    'app'   => $node['app'],
                );
                $result[] = $prevNode;
                $result[] = array(
                    'time'  => 60,
                    'app'   => null,
                );
                $result[] = array(
                    'time'  => $node['time'] - $prevNode['time'],
                    'app'   => $node['app'],
                );
                $result = array_merge($result, $statistic);
                break;
            }
        }
        return $result;
    }

    protected static function _fillInterval($curr, $interval, &$partsOfWork)
    {
        $result = array();
        $lastIndex = 0;
        $end = $curr + $interval;
        while ($curr < $end && !empty($partsOfWork)) {
            $neededTime = $end - $curr;
            $partOfWork = array_shift($partsOfWork);
            if ($neededTime > $partOfWork['time']) {
                $work2Add = $partOfWork;
            } else {
                $work2Add = array(
                    'time'  => $neededTime,
                    'app'   => $partOfWork['app']
                );
                $partOfWork['time'] -= $neededTime;
                array_unshift($partsOfWork, $partOfWork);
            }
            $curr += $work2Add['time'];
            if (0 !== $lastIndex) {
                if ($result[$lastIndex]['app'] === $work2Add['app']) {
                    $result[$lastIndex]['time'] += $work2Add['time'];
                    continue;
                }
            }
            $result[++$lastIndex] = $work2Add;
        }
        return $result;
    }

    protected static function _generateIntervals($min, $max, $length, $count = null)
    {
        $section = ($max + $min) / 2;
        if (!$count) {
            $count = floor($length / $section);
        }
        $result = array();
        $startPoint = 0;
        for ($i = 1; $i <= $count; $i++) {
            if ($i == $count) {
                $result[] = $length - $startPoint;
                break;
            }
            $minRange = max($startPoint + $min, ($i + 1) * $section - $max);
            $maxRange = min($startPoint + $max, ($i + 1) * $section - $min);
            $point = rand($minRange * 100, $maxRange * 100) / 100;
            $result[] = $point - $startPoint;
            $startPoint = $point;
        }

        return $result;
    }
}