# README.md

# Radix Framework

Radix Framework är ett fristående PHP‑ramverk som levereras som ett Composer‑paket.  
Det är byggt för att vara enkelt att konsumera från andra projekt – utan att du behöver checka in framework‑kod i din applikation.

För en komplett, körbar referensapp/starter, se:
- **`mattablues/radix-app`**: https://github.com/mattablues/radix-app

<!-- START doctoc generated TOC please keep comment here to allow auto update -->
<!-- DON'T EDIT THIS SECTION, INSTEAD RE-RUN doctoc TO UPDATE -->
<!-- doctoc will insert TOC here -->

- [Installation](#installation)
- [Snabbstart](#snabbstart)
- [Funktioner i Radix Framework (översikt)](#funktioner-i-radix-framework-översikt)
- [Dokumentation](#dokumentation)
- [Projektlayout](#projektlayout)
- [Autoloading](#autoloading)
- [För ramverksutvecklare](#för-ramverksutvecklare)
  - [Lokalt dev-setup](#lokalt-dev-setup)
  - [Kommandon (composer scripts)](#kommandon-composer-scripts)
  - [Tester (PHPUnit)](#tester-phpunit)
  - [Statisk analys (PHPStan)](#statisk-analys-phpstan)
  - [Mutationstester (Infection)](#mutationstester-infection)
  - [Kodstil (PHP-CS-Fixer)](#kodstil-php-cs-fixer)
  - [CI (GitHub Actions)](#ci-github-actions)
- [Versionering och användning från andra projekt](#versionering-och-användning-från-andra-projekt)
- [Licens](#licens)

<!-- END doctoc generated TOC please keep comment here to allow auto update -->

## Installation

I en konsumerande applikation:

```bash
composer require mattablues/radix-framework
```

Ramverket kräver:

- PHP **8.3** eller senare inom 8.x‑serien
- Extension: `ext-libxml` (för XML‑funktionalitet)

## Snabbstart

Ett enkelt exempel på hur Radix kan användas:

```php
<?php

declare(strict_types=1);

use Radix\File\Reader;
use Radix\File\Writer;

// Skriv JSON till fil
Writer::json(__DIR__ . '/data.json', [
    'id'   => 1,
    'name' => 'Alice',
]);

// Läs tillbaka som assoc-array
$data = Reader::json(__DIR__ . '/data.json', assoc: true);

var_dump($data);
// ['id' => 1, 'name' => 'Alice']
```

## Funktioner i Radix Framework (översikt)

Radix Framework innehåller bland annat:

- **Routing & HTTP**
  - Flexibel router (`Radix\Routing`) med middleware‑stöd (`Radix\Middleware`)
  - HTTP‑request/response‑abstraktion (`Radix\Http`)

- **Controller‑lager**
  - Bascontrollers för web och API (`Radix\Controller\AbstractController`, `AbstractApiController`)

- **Console & CLI**
  - Konsolapplikation (`Radix\Console\ConsoleApplication`)
  - Kommandoregister (`CommandsRegistry`)
  - Inbyggda kommandon för bl.a. migrationer och generatorer (`Radix\Console\Commands\...`)

- **Databas, ORM & migrationer**
  - Databasanslutningar och manager (`Radix\Database\Connection`, `DatabaseManager`)
  - Query builder (`Radix\Database\QueryBuilder`)
  - Enkel ORM‑modellering (`Radix\Database\ORM`)
  - Migrationer + CLI‑stöd (`Radix\Database\Migration`)

- **Container & Service Providers**
  - Dependency Injection‑container (`Radix\Container\Container`, `ApplicationContainer`)
  - Definitioner, parametrar och referenser (`Definition`, `Parameter`, `Reference`, `Resolver`)
  - Service providers (`Radix\ServiceProvider`)

- **View‑motor**
  - Template‑engine med ratio‑templates, extends/yield, komponenter och slots (`Radix\Viewer\RadixTemplateViewer`)
  - Cache‑hantering för vyer och enkla filter

- **Session & autentiseringsstöd**
  - Anpassningsbar sessionshanterare (`Radix\Session\RadixSessionHandler`) med fil- eller databaserad lagring

- **Fil, dataformat & utilities**
  - Fil‑ och data‑hjälpare (`Radix\File`) för CSV/NDJSON/XML/JSON m.m.
  - Datum/tid‑hjälpare (`Radix\DateTime`)
  - Stödklasser (`Radix\Support`), collections (`Radix\Collection`), enums (`Radix\Enums`)

- **Övrigt**
  - Event dispatcher (`Radix\EventDispatcher`)
  - Mailer‑stöd (`Radix\Mailer`)
  - Felhantering och fel‑svar (`Radix\Error`)

## Dokumentation

För en djupdykning i hur Radix fungerar, se:

- **Docs-index:** `docs/INDEX.md`

För en komplett, fungerande applikation som använder ramverket, se:

- **Radix App:** https://github.com/mattablues/radix-app

## Projektlayout

Radix är ett eget repo och konsumeras via Composer:

- `mattablues/radix-framework` innehåller själva ramverket.
- Applikationer (t.ex. `mattablues/radix-app` eller ditt eget projekt) lägger till ramverket via:

  ```bash
  composer require mattablues/radix-framework
  ```

Ingen framework‑kod ska checkas in direkt i app‑repon.

## Autoloading

Ramverket följer PSR‑4:

```json
"autoload": {
  "psr-4": {
    "Radix\\": "src/"
  },
  "files": [
    "support/helpers.php"
  ]
}
```

Tester autoloadas via:

```json
"autoload-dev": {
  "psr-4": {
    "Radix\\Tests\\": "tests/"
  }
}
```

## För ramverksutvecklare

Det här avsnittet är framför allt för dig som utvecklar **själva Radix Framework** (inte bara använder det som beroende).

### Lokalt dev-setup

I `mattablues/radix-framework`‑repon:

```bash
composer install
```

### Kommandon (composer scripts)

De vanligaste kommandona:

```bash
composer format
composer format:check
composer stan
composer test
composer infect
composer infect:pcov
composer infect:xdebug
```

Direkt från `composer.json`:

```json
"scripts": {
  "stan": "phpstan analyse -c phpstan.neon.dist",
  "test": "php -d xdebug.mode=off vendor/bin/phpunit",
  "infect": "php tools/infection.php",
  "infect:pcov": "php tools/infection.php pcov",
  "infect:xdebug": "php tools/infection.php xdebug",
  "format": "@php tools/php-cs-fixer.php",
  "format:check": "@php tools/php-cs-fixer.php --dry-run --diff"
}
```

### Tester (PHPUnit)

Ramverket använder PHPUnit 11.  
Kör alla tester:

```bash
composer test
```

### Statisk analys (PHPStan)

```bash
composer stan
```

### Mutationstester (Infection)

```bash
composer infect
# eller
composer infect:pcov
composer infect:xdebug
```

Inställningar finns i `infection.json.dist`.

### Kodstil (PHP-CS-Fixer)

Auto-fix:

```bash
composer format
```

Endast kontroll (bra i CI):

```bash
composer format:check
```

### CI (GitHub Actions)

Repo:t innehåller GitHub Actions‑workflows som kör:

- Kodstil (`composer format:check`)
- Tester (`composer test`)
- PHPStan (`composer stan`)
- Schemalagd Infection (om aktiverad i workflow)

## Versionering och användning från andra projekt

Rekommenderat:

1. Tagga releaser med semantisk versionering, t.ex.:

   ```bash
   git tag -a v0.1.0 -m "First Radix Framework release"
   git push origin v0.1.0
   ```

2. I konsumerande applikation:

   ```bash
   composer require mattablues/radix-framework:^0.1
   ```

## Licens

MIT. Se `LICENSE`.
