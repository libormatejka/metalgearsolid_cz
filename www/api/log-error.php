<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$message = mb_substr(trim((string) ($body['message'] ?? '')), 0, 120);
$error   = mb_substr(trim((string) ($body['error']   ?? '')), 0, 200);

writeLog('JS_ERROR', $message ?: '(no message)', $error ?: '(no detail)');

echo json_encode(['ok' => true]);
