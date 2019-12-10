<?php


namespace LuigisboxSearchSuite\Models;


class Helper
{
    const INTERVAL = 86400;

    public static function getLogfile($filePath)
    {
        if (file_exists($filePath)) {
            try {
                $f = fopen($filePath);
                $log = json_decode($f, filesize($filePath));
                fclose($f);
            } catch (\Exception $ex) {
                self::writeLog('Luigi\'s Box file update.txt not readable');
                return false;
            }
            return $log;
        }

        return false;
    }

    private static function writeFile($filePath, $log)
    {
        $f = fopen($filePath, 'w');
        fwrite($f, $log);
        fclose($f);
    }

    public static function setLogfile($filePath, $log)
    {
        try {
            self::writeFile($filePath, json_encode($log));
        } catch (\Exception $ex) {
            self::writeLog('Luigi\'s Box log file is not writable');
        }
    }


    public static function isIndexValid($filePath)
    {
        $now = date('U');

        $last = 0;

        if ($log = self::getLogfile($filePath)) {
            $last = $log->invalidated;
        }

        $diff = $now - $last;

        return ($diff < self::INTERVAL);
    }

    public static function isIndexFinished($filePath)
    {
        if ($log = self::getLogfile($filePath)) {
            return $log->finished;
        }

        return true;
    }

    public static function isIndexRunning($filePath)
    {
        if ($log = self::getLogfile($filePath)) {
            return $log->running;
        }

        return false;
    }

    public static function markIndexFinished($filePath)
    {
        if ($log = self::getLogfile($filePath)) {
            $log->finished = true;
            $log->running = false;

            self::setLogfile($filePath, $log);
        }
    }

    public static function markIndexRunning($filePath)
    {
        if ($log = self::getLogfile($filePath)) {
            $log->finished = false;
            $log->running = true;

            self::setLogfile($filePath, $log);
        }
    }

    public static function setIndexInvalidationTimestamp($filePath)
    {
        $now = date('U');

        $log = ['invalidated' => $now, 'running' => false, 'finished' => false];

        self::setLogfile($filePath, $log);

        self::writeLog('Luigi\'s Box index invalidated');
    }

    private static function writeLog($msg)
    {
        Shopware()->Container()->get('pluginlogger')->info($msg);
        error_log($msg);
    }

}
