<?php

class Log {
    private static function write(string $level, string $message): void {
        if (defined('LOG_ENABLED') && LOG_ENABLED === true) {
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] [$level] $message");
        }
    }

    public static function info(string $message): void {
        self::write('INFO', $message);
    }

    public static function error(string $message): void {
        self::write('ERROR', $message);
    }

    public static function warning(string $message): void {
        self::write('WARNING', $message);
    }
}
