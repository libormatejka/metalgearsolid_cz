#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.local.php';

const LIST_URL   = 'https://generativelanguage.googleapis.com/v1beta/files';
const DELETE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

// ── načti seznam souborů (stránkování) ───────────────────────────────────────
$files     = [];
$pageToken = null;

do {
    $url = LIST_URL . '?key=' . GEMINI_API_KEY . '&pageSize=100'
         . ($pageToken ? '&pageToken=' . urlencode($pageToken) : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $data      = json_decode(curl_exec($ch), true);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($httpCode !== 200) {
        exit("Chyba načítání seznamu (HTTP {$httpCode}).\n");
    }

    $files     = array_merge($files, $data['files'] ?? []);
    $pageToken = $data['nextPageToken'] ?? null;
} while ($pageToken);

if (!$files) {
    exit("Žádné soubory k smazání.\n");
}

echo "Nalezeno " . count($files) . " soubor(ů). Mažu...\n\n";

// ── smaž jeden po druhém ──────────────────────────────────────────────────────
$deleted = 0;
$skipped = 0;
$errors  = 0;

foreach ($files as $file) {
    $name        = $file['name'];        // např. "files/abc123"
    $displayName = $file['displayName'] ?? $name;

    $url = DELETE_URL . $name . '?key=' . GEMINI_API_KEY;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($httpCode === 200) {
        echo "   ✓ Smazán: {$displayName}\n";
        $deleted++;
    } elseif ($httpCode === 403) {
        // soubor patří jinému API klíči — expiruje sám, nelze smazat
        $skipped++;
    } else {
        echo "   ✗ Chyba (HTTP {$httpCode}): {$displayName}\n";
        $errors++;
    }
}

echo "\nHotovo. Smazáno: {$deleted}"
   . ($skipped ? ", přeskočeno (cizí klíč): {$skipped}" : "")
   . ($errors  ? ", chyby: {$errors}" : "")
   . ".\n";

// ── vymaž library-files.json aby chat.php neposílal mrtvé reference ──────────
$outputFile = __DIR__ . '/../config/library-files.json';
file_put_contents($outputFile, '[]');
echo "✓ config/library-files.json vymazán.\n";
