# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

MetalGearSolid.cz je fanouškovský web o sérii Metal Gear Solid. Obsahuje:
- **Hlavní stránku** (`www/index.html`) — video hero, grid produktů ze `www/products.json`, floating chat widget
- **Timeline** (`www/timeline.html`) — alternující vertikální timeline ze `www/timeline.json`
- **Chat** (`www/chat.html`) — full-page dvousloupcový chat (sidebar + main)
- **PHP chatbot backend** (`www/api/chat.php`) — proxy na Gemini API

Design: tmavý/gaming styl, bg `#0d0d0d`, gold `#c9a84c`, green `#4caf82`.

## Docker environment

All development runs inside Docker containers (project name: `collectorboycz`).

```bash
make up             # Start containers (http://localhost)
make up:build       # Start with forced rebuild
make down           # Stop containers
make logs           # Tail logs
make exec:php       # Shell into PHP container
make exec:node      # Shell into Node container
```

Services:
- **nginx** → port 80 (serves `www/`)
- **php** → PHP 8.2-fpm (workdir `/srv/`, projekt je mountován na `/srv/`)
- **mariadb** → port 3306, DB name `collectorboycz`
- **phpmyadmin** → port 10000
- **node** → port 8080

## Deployment

Production je nasazena via FTP na `ftp.libor-matejka.cz/metalgearsolid_cz/` pomocí `deploy/deployment.php`.

```bash
make deploy-test env=prod   # Dry run
make deploy env=prod         # Live deploy
```

Config je v `deployment-prod.ini`. Soubor `config/config.local.php` **není** v ignore listu deploymentu — nahraje se automaticky pokud existuje lokálně.

## Chatbot (Gemini API)

### Architektura
- Frontend volá jen `/api/chat.php` — API klíč se nikdy nedostane na frontend
- `www/api/chat.php` — PHP proxy na Gemini API
- `www/api/_log.php` — sdílená logovací funkce (includována v chat.php a log-error.php)
- `www/api/log-error.php` — endpoint pro logování JS chyb z frontendu
- `config/config.local.php` — obsahuje `GEMINI_API_KEY` (mimo web root, v `.gitignore`)
- `config/library-files.json` — auto-generováno upload scriptem, obsahuje Gemini file URI

### Gemini payload struktura
- `system_instruction` → pouze SYSTEM_PROMPT (text only; fileData zde nefunguje)
- `contents[0]` → user: `"Zde je tvá znalostní databáze:"` + pro každý soubor popis + fileData (camelCase!)
- `contents[1]` → model: potvrzení přijetí databáze
- `contents[n]` → chat history + aktuální dotaz
- Klíče jsou **camelCase**: `fileData`, `mimeType`, `fileUri`

### Logování
- Log se ukládá do `config/chat.log` (mimo web root)
- Každý řádek: `[datum čas] [IP] STATUS | dotaz | odpověď (300 chars)`
- Statusy: `OK`, `ERROR`, `JS_ERROR`
- Pro debug payloadu: `config/payload-debug.json` (přepíše se každým requestem)
- Zobrazit log: `tail -f config/chat.log`
- **Důležité:** `config/chat.log` a `config/payload-debug.json` musí být writable PHP-FPM procesem (uid 1000). Pokud je soubor vytvořen jako root (např. přes `docker exec`), bude mít špatné permissions — smaž ho a nech ho vytvořit přes web request.

### Znalostní databáze (library)
Soubory jsou v `library/` (podsložky jako `Lore/`, `Profiles/`, `Timelines/`).

**Config souborů:** `config/library.json` — edituj ručně, popisuje co každý soubor obsahuje:
```json
[
  {
    "file": "../library/Lore/Lore_01.txt",
    "description": "Kompletní příběh série Metal Gear Solid - Verze 1"
  }
]
```
Cesta `file` je relativní k `config/` složce. `description` se použije jako `displayName` na Gemini a jako popis před souborem v payloadu.

**Nahrání souborů na Gemini:**
```bash
make upload-library         # Přenahraje jen expirující (do 24h)
make upload-library:force   # Přenahraje vše
```
Soubory na Gemini expirují po 48h. Upload script (`tools/upload-library.php`) uloží výsledky do `config/library-files.json`.

## Sdílená JS logika chatu

`www/js/snake-ai.js` — IIFE modul `SnakeAI` sdílený widgetem i full-page chatem.

```js
SnakeAI.init({
  messagesEl, inputEl, sendEl,
  newChatEl?,   // tlačítko nové konverzace (jen full-page)
  historyEl?,   // sidebar s historií (jen full-page)
})
```

Konfigurace (`CONFIG`):
- `botName`: `'Codec (Frequency 140.85)'`
- `welcomeMsg`: `'Kept you waiting, huh? 🐍\nTady Otacon — ...'`
- `storageKey`: `'mgs-sessions'` — localStorage pro historii konverzací
- `currentKey`: `'mgs-current'` — localStorage pro aktivní konverzaci (přežije refresh)

Widget vs full-page se rozlišuje přes `messagesEl.closest('.chat-main')`.

## Konfigurovatelná data

| Soubor | Účel |
|---|---|
| `www/products.json` | Grid produktů na hlavní stránce |
| `www/timeline.json` | Data pro timeline stránku |
| `config/library.json` | Seznam souborů pro chatbota + jejich popis |

## Deployment checklist

Po každém nasazení ověř:
1. `config/config.local.php` existuje na serveru (s platným `GEMINI_API_KEY`)
2. `config/library-files.json` existuje (nebo spusť `make upload-library:force`)
3. `config/` adresář je writable PHP procesem na serveru

## Future PHP/Nette app (not yet present)

When the app is scaffolded, the Makefile already wires up:
- **DB reset**: `make db` (drop → create → load fixtures)
- **Build**: `make build` (webpack + critical CSS via Node)
- **Linting**: `make cs` / `make cs-fix` (PHP_CodeSniffer), `make phpstan` (level 7), `make eslint`, `make stylelint`
- **Tests**: `make tests` (PHPUnit entity + integration tests), `make db-tests` (Doctrine schema validation)
- **Full QA**: `make qa`
- **Code generation**: `make generate` (clown/generator)
