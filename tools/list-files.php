#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.local.php';

const LIST_URL = 'https://generativelanguage.googleapis.com/v1beta/files';

$ch = curl_init(LIST_URL . '?key=' . GEMINI_API_KEY . '&pageSize=100');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

if ($httpCode !== 200) {
    exit("Chyba API (HTTP {$httpCode}): {$response}\n");
}

$data  = json_decode($response, true);
$files = $data['files'] ?? [];

if (!$files) {
    exit("Žádné soubory nenalezeny — pravděpodobně expiraly nebo nebyly nahrány.\n");
}

echo count($files) . " soubor(ů) na Gemini:\n\n";

foreach ($files as $f) {
    $state      = $f['state'] ?? '?';
    $stateLabel = $state === 'ACTIVE' ? '✓ ACTIVE' : "⚠ {$state}";
    $expires    = isset($f['expirationTime'])
        ? 'expiruje ' . date('d.m.Y H:i', strtotime($f['expirationTime']))
        : '';

    echo "📄 " . ($f['displayName'] ?? $f['name']) . "\n";
    echo "   Stav:  {$stateLabel}\n";
    echo "   URI:   {$f['uri']}\n";
    if ($expires) echo "   {$expires}\n";
    echo "\n";
}
