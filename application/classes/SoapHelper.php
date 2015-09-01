<?php

namespace Application;

class SoapHelper
{
    const EVENT_TYPE        = 4;

    const WSDL_URI          = '../application/configs/api.xml';

    const SERVER_METHOD     = 'logEventsArray';

    public static function sendStatistics($accountKey, $statistics)
    {
        $logArray = array();
        $counter = 1;
        foreach ($statistics as $date => $dayStatistics) {
            $dateTimestamp = strtotime($date) + StatisticHelper::DAY_START * 60;
            foreach ($dayStatistics as $user => $userStatistics) {
                $timeStart = $dateTimestamp;
                foreach ($userStatistics as $node) {
                    $timeEnd = $timeStart + $node['time'] * 60;
                    if ($node['app']) {
                        if (!$node['app']['name']) {
                            $node['app']['name'] = 'Non-named application';
                        }
                        if (!$node['app']['title']) {
                            $node['app']['title'] = $node['app']['name'];
                        }
                        if (!$node['app']['path']) {
                            $node['app']['path'] = 'C:\\Program Files\\' . $node['app']['name'] . '\\'
                                . strtolower(str_replace(' ', '_', $node['app']['name'])) . '.exe';
                        }
                        $logArray[$counter] = array(
                            'eventId'       => $counter,
                            'accountKey'    => $accountKey,
                            'userName'      => 'domain\\' . $user . '@HOSTNAME',
                            'timeStart'     => date('c', $timeStart),
                            'timeEnd'       => date('c', $timeEnd),
                            'eventType'     => self::EVENT_TYPE,
                            'appName'       => $node['app']['name'],
                            'appTitle'      => $node['app']['title'],
                            'appPath'       => $node['app']['path'],
                            'urlVisited'    => $node['app']['url'],
                            'screen'        => '',
                            'extras'        => '',
                            'seconds'       => 0,
                        );
                        $counter++;
                    }
                    $timeStart = $timeEnd;
                }
            }
        }

        $client = new \Zend_Soap_Client(self::WSDL_URI);
        return $client->{self::SERVER_METHOD}($logArray);
    }
}