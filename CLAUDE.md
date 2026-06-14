# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

MetalGearSolid.cz is currently a static "Coming Soon" landing page (`www/index.html`). The Docker stack and Makefile are scaffolded from the sibling project **collectorboycz** and anticipate a future Nette/PHP application, but that app does not yet exist in this repo.

## Docker environment

All development runs inside Docker containers (project name: `collectorboycz`).

```bash
make up           # Start containers (http://localhost)
make up:build     # Start with forced rebuild
make down         # Stop containers
make logs         # Tail logs
make exec:php     # Shell into PHP container
make exec:node    # Shell into Node container
```

Services:
- **nginx** → port 80 (serves `www/`)
- **php** → PHP 8.2-fpm
- **mariadb** → port 3306, DB name `collectorboycz`
- **phpmyadmin** → port 10000
- **node** → port 8080

## Deployment

Production is deployed via FTP to `ftp.libor-matejka.cz/metalgearsolid_cz/` using the custom `deploy/deployment.php` tool.

```bash
# Dry run (test mode)
make deploy-test env=prod

# Live deploy
make deploy env=prod
```

Config is in `deployment-prod.ini`. After upload, `temp/cache` is purged automatically. The deployment tool excludes dev assets, config, logs, media, and CI files — see the `ignore` list in `deployment-prod.ini`.

## Future PHP/Nette app (not yet present)

When the app is scaffolded, the Makefile already wires up:
- **DB reset**: `make db` (drop → create → load fixtures)
- **Build**: `make build` (webpack + critical CSS via Node)
- **Linting**: `make cs` / `make cs-fix` (PHP_CodeSniffer), `make phpstan` (level 7), `make eslint`, `make stylelint`
- **Tests**: `make tests` (PHPUnit entity + integration tests), `make db-tests` (Doctrine schema validation)
- **Full QA**: `make qa`
- **Code generation**: `make generate` (clown/generator)
