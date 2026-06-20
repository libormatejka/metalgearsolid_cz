#!/usr/bin/env php
<?php

/**
 * Nahraje soubory ze složky library/ do Gemini Files API.
 * Uloží výsledné URI do config/library-files.json.
 *
 * Použití:
 *   php tools/upload-library.php           — nahraje jen nové / expirující do 24h
 *   php tools/upload-library.php --force   — přenahraje vše vždy
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.local.php';

const UPLOAD_URL       = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
const LIST_URL         = 'https://generativelanguage.googleapis.com/v1beta/files';
const LIBRARY_DIR      = __DIR__ . '/../library';
const LIBRARY_CONFIG   = __DIR__ . '/../config/library.json';
const OUTPUT_FILE      = __DIR__ . '/../config/library-files.json';
const RENEW_BEFORE_HOURS = 24;

$force = in_array('--force', $argv ?? [], true);

$mimeTypes = [
    'txt'  => 'text/plain',
    'pdf'  => 'application/pdf',
    'md'   => 'text/plain',
    'json' => 'application/json',
    'html' => 'text/html',
];

// ── načti config ──────────────────────────────────────────────────────────────
if (!is_file(LIBRARY_CONFIG)) {
    exit("Config config/library.json neexistuje.\n");
}

$libraryConfig = json_decode(file_get_contents(LIBRARY_CONFIG), true);
if (!is_array($libraryConfig) || !$libraryConfig) {
    exit("Config config/library.json je prázdný nebo neplatný.\n");
}

echo ($force ? "[--force] " : "") . "Nalezeno " . count($libraryConfig) . " soubor(ů) v configu.\n\n";

// ── načti stávající soubory na Gemini ─────────────────────────────────────────
echo "Načítám stávající soubory z Gemini...\n";
$existingByName = [];
foreach (listGeminiFiles() as $gf) {
    $existingByName[$gf['displayName']] = $gf;
}

// ── nahraj / přeskoč / obnov ──────────────────────────────────────────────────
$result = [];

foreach ($libraryConfig as $entry) {
    $relativePath = $entry['file'] ?? '';
    $description  = $entry['description'] ?? $relativePath;
    $path         = realpath(dirname(LIBRARY_CONFIG) . '/' . $relativePath);

    if (!is_file($path)) {
        echo "⚠ Soubor nenalezen: {$relativePath}\n\n";
        continue;
    }

    $ext      = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeType = $mimeTypes[$ext] ?? 'text/plain';
    $sizeKb   = number_format(filesize($path) / 1024, 1);

    echo "📄 {$relativePath} ({$sizeKb} KB)\n";
    echo "   {$description}\n";

    $existing    = $existingByName[$description] ?? null;
    $needsUpload = true;

    if (!$force && $existing) {
        $expiresAt = strtotime($existing['expirationTime'] ?? '');
        $hoursLeft = $expiresAt ? ($expiresAt - time()) / 3600 : 0;

        if ($hoursLeft > RENEW_BEFORE_HOURS) {
            echo "   ✓ Přeskočeno — expiruje za " . round($hoursLeft) . " h ({$existing['name']})\n\n";
            $result[] = [
                'displayName'    => $description,
                'name'           => $existing['name'],
                'uri'            => $existing['uri'],
                'mimeType'       => $mimeType,
                'expirationTime' => $existing['expirationTime'],
            ];
            $needsUpload = false;
        } else {
            echo "   ⚠ Expiruje za " . round($hoursLeft, 1) . " h — přenahrávám...\n";
        }
    }

    if ($needsUpload) {
        $uploaded = uploadFile($path, $description, $mimeType);
        if ($uploaded) {
            $hoursLeft = ($uploaded['expiresAt'] - time()) / 3600;
            echo "   ✓ Nahráno: {$uploaded['name']} (expiruje za ~" . round($hoursLeft) . " h)\n\n";
            $result[] = [
                'displayName'    => $description,
                'name'           => $uploaded['name'],
                'uri'            => $uploaded['uri'],
                'mimeType'       => $mimeType,
                'expirationTime' => date(DATE_ATOM, $uploaded['expiresAt']),
            ];
        } else {
            echo "   ✗ Chyba při nahrávání!\n\n";
        }
    }
}

// ── ulož výsledek ─────────────────────────────────────────────────────────────
file_put_contents(OUTPUT_FILE, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "✅ Hotovo! URI uložena do config/library-files.json\n";
echo "   Celkem: " . count($result) . " soubor(ů)\n\n";

foreach ($result as $f) {
    $exp = isset($f['expirationTime'])
        ? ' — expiruje ' . date('d.m.Y H:i', strtotime($f['expirationTime']))
        : '';
    echo "   • {$f['displayName']}{$exp}\n     {$f['uri']}\n";
}

// ── funkce ────────────────────────────────────────────────────────────────────

function uploadFile(string $path, string $displayName, string $mimeType): ?array
{
    $boundary = 'boundary_' . bin2hex(random_bytes(8));
    $content  = file_get_contents($path);
    $metadata = json_encode(['file' => ['display_name' => $displayName]]);

    $body = "--{$boundary}\r\n"
        . "Content-Type: application/json; charset=utf-8\r\n\r\n"
        . $metadata . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: {$mimeType}\r\n\r\n"
        . $content . "\r\n"
        . "--{$boundary}--";

    $ch = curl_init(UPLOAD_URL . '?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: multipart/related; boundary={$boundary}",
            'Content-Length: ' . strlen($body),
            'X-Goog-Upload-Protocol: multipart',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    unset($ch);

    if ($error) {
        echo "   cURL chyba: {$error}\n";
        return null;
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !isset($data['file']['uri'])) {
        echo "   API chyba ({$httpCode}): " . ($data['error']['message'] ?? $response) . "\n";
        return null;
    }

    return [
        'name'      => $data['file']['name'],
        'uri'       => $data['file']['uri'],
        'expiresAt' => strtotime($data['file']['expirationTime'] ?? '+48 hours'),
    ];
}

function listGeminiFiles(): array
{
    $ch = curl_init(LIST_URL . '?key=' . GEMINI_API_KEY . '&pageSize=100');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $data = json_decode(curl_exec($ch), true);
    unset($ch);

    return array_map(fn($f) => [
        'name'           => $f['name'],
        'displayName'    => $f['displayName'] ?? '',
        'uri'            => $f['uri'],
        'expirationTime' => $f['expirationTime'] ?? '',
    ], $data['files'] ?? []);
}
