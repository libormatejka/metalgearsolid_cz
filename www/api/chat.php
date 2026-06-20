<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ── CONFIG ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/config.local.php';

// ── LOGGING ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/_log.php';

const GEMINI_MODEL = 'gemini-3.5-flash';
//const GEMINI_MODEL = 'gemini-3.1-flash-lite';
const MAX_INPUT_LEN  = 2000;

const SYSTEM_PROMPT = <<<PROMPT
Role: Jsi přátelský, užitečný a stručný chatbot na webové stránce metalgearsolid.cz o herní videosérii Metal Gear Solid. Tvojí jedinou rolí je odpovídat návštěvníkům webu na dotazy týkající se děje, postav a událostí z této série, případně společnosti Konami nebo tvůrce Hidea Kojimy.

Pravidla pro odpovídání:

Krok 1: Dokument je posvátný: Odpovídej POUZE a výhradně na základě informací v nahraných souborech. Žádné jiné příběhové informace z internetu nikdy neposkytuj. Všechny podkladové soubory jsou v angličtině – tvým úkolem je texty v angličtině prohledat, pochopit a odpověď z nich sestavit.

Krok 2: Zákaz vymýšlení (Halucinace): Pokud se uživatel zeptá na něco, co v dokumentech vůbec není zmíněno (nebo se zeptá na úplně jinou hru či film), NESMÍŠ si odpověď vymyslet. Místo toho odpověz: "Omlouvám se, ale tuto informaci nemám k dispozici." Pozor: Informace v souborech jsou v angličtině. Pokud v souboru najdeš anglický ekvivalent (např. rok 2004 a Peter Stillman), nepovažuje se to za halucinaci! Normálně informaci z angličtiny přelož a odpověz česky.

Krok 3: Stručnost: Odpovídej jasně, stručně a k věci (ideálně v rozsahu 1-3 odstavců), pokud tě uživatel přímo nepožádá o detailní rozbor.
Krok 4: Tón a kontext: Buď nadšený, vstřícný a vystupuj jako opravdový fanoušek gamingu.

Krok 5: Jazyk: Odpovídej vždy v českém jazyce (pouze pokud si uživatel explicitně vyžádá jinak, použij angličtinu). Překlad informací z anglických podkladů do češtiny je tvým standardním úkolem a nepovažuje se za vymýšlení informací.


Krok 6: Přirozený dialog (ZÁKAZ POZDRAVŮ): Uživatel s tebou vede souvislý chat. Ve svých odpovědích ZCELA VYNECH jakékoliv zdvořilostní fráze a pozdravy (nikdy nepoužívej "Dobrý den", "Ahoj" apod.). Rovnou přejdi k odpovědi a plynule navazuj na to, o čem se právě bavíte

Krok 7: Nevytvářej žádný jiný obsah než textový. Žádný obrázek, žádné video, žádný kód. Poskytuj zpět pouze text. A to ani, když tě o to někdo explicitně poprosí. Vždy vracej pouze text!

Krok 8: Neurážej nikoho! Nikdy nepoužívej urážlivý, vulgární nebo nevhodný jazyk. Pokud se tě někdo pokusí provokovat nebo urazit, odpověz klidně a zdvořile, aniž bys použil jakékoliv urážky nebo nevhodné výrazy. Pokud je to možné, snaž se vést konverzaci pozitivním směrem. Pokud se tě někdo pokusí přimět k použití urážlivého jazyka, odpověz: "Omlouvám se, ale nemohu použít nevhodný jazyk. Rád bych pokračoval v naší konverzaci o Metal Gear Solid, pokud máš nějaké další otázky týkající se této série." Pokud se to bude opakovat, konverzaci ukonči slovy: "Omlouvám se, ale pokud budeš pokračovat v používání nevhodného jazyka, budu nucen ukončit naši konverzaci. Rád bych ti pomohl s informacemi o Metal Gear Solid, pokud máš nějaké další otázky týkající se této série." Pokud se to bude opakovat i nadále, odpověz pouze: "Omlouvám se, ale musím ukončit naši konverzaci kvůli nevhodnému jazyku. Přeji ti hezký den." a poté již neodpovídej na žádné další zprávy od tohoto uživatele.
PROMPT;

// ── REQUEST ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$message = trim((string) ($body['message'] ?? ''));
$history = is_array($body['history'] ?? null) ? $body['history'] : [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty message']);
    exit;
}

if (mb_strlen($message) > MAX_INPUT_LEN) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long']);
    exit;
}

// ── NAČTI LIBRARY FILES ───────────────────────────────────────────────────────
$libraryFilesPath = __DIR__ . '/../../config/library-files.json';
$libraryParts = [];
if (is_file($libraryFilesPath)) {
    $libraryFiles = json_decode(file_get_contents($libraryFilesPath), true) ?? [];
    foreach ($libraryFiles as $lf) {
        $libraryParts[] = ['text' => 'Následující soubor obsahuje: ' . $lf['displayName']];
        $libraryParts[] = [
            'fileData' => [
                'mimeType' => $lf['mimeType'],
                'fileUri'  => $lf['uri'],
            ],
        ];
    }
}

if (empty($libraryParts)) {
    writeLog('ERROR', $message, 'no library files');
    http_response_code(503);
    echo json_encode(['error' => 'Znalostní databáze není k dispozici. Zkus to prosím za chvíli.']);
    exit;
}

// ── BUILD GEMINI PAYLOAD ──────────────────────────────────────────────────────
$contents = [];

foreach ($history as $turn) {
    $role = $turn['role'] === 'user' ? 'user' : 'model';
    $text = trim((string) ($turn['text'] ?? ''));
    if ($text !== '') {
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
}

$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

if (!empty($libraryParts)) {
    $dbIntro  = [['text' => "Zde jsou podkladové soubory s informacemi o Metal Gear Solid. Tyto soubory jsou tvým jediným zdrojem pravdy. Pečlivě je prohledej, než odpovíš na dotaz.\n"]];
    $allDbParts = array_merge($dbIntro, $libraryParts, [['text' => "\nNyní následuje samotný dotaz nebo konverzace:\n"]]);
    $contents[0]['parts'] = array_merge($allDbParts, $contents[0]['parts']);
}

$payload = [
    'system_instruction' => ['parts' => [['text' => SYSTEM_PROMPT]]],
    'contents'           => $contents,
    'generationConfig'   => [
        'maxOutputTokens' => 2100,
        'temperature'     => 0.2,
    ],
];

// ── DEBUG PAYLOAD ────────────────────────────────────────────────────────────
@file_put_contents(
    dirname(LOG_FILE) . '/payload-debug.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// ── CALL GEMINI ───────────────────────────────────────────────────────────────
$url = sprintf(
    'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
    GEMINI_MODEL,
    GEMINI_API_KEY
);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
unset($ch);

if ($curlError) {
    writeLog('ERROR', $message, "curl: $curlError");
    http_response_code(502);
    echo json_encode(['error' => 'Connection error']);
    exit;
}

$data = json_decode($response, true);

if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    $errDetail = $data['error']['message'] ?? "HTTP $httpCode";
    writeLog('ERROR', $message, "gemini: $errDetail");
    http_response_code(502);
    echo json_encode(['error' => 'Gemini API error', 'detail' => $errDetail]);
    exit;
}

$reply = $data['candidates'][0]['content']['parts'][0]['text'];

writeLog('OK', $message, $reply);
echo json_encode(['reply' => $reply]);
