<?php

declare(strict_types=1);

define('LOG_FILE', __DIR__ . '/../../config/chat.log');

function writeLog(string $status, string $message, string $detail = ''): void
{
    $ts     = date('Y-m-d H:i:s');
    $ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $msg    = mb_substr($message, 0, 120);
    $detail = $detail !== '' ? ' | ' . str_replace(["\r", "\n"], ' ', mb_substr($detail, 0, 300)) : '';
    $line   = "[$ts] [$ip] $status | $msg$detail" . PHP_EOL;
    $exists = file_exists(LOG_FILE);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    if (!$exists && file_exists(LOG_FILE)) {
        @chmod(LOG_FILE, 0666);
    }
}
