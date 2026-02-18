# Radix Framework

Radix Framework är ett fristående PHP‑ramverk som levereras som ett Composer‑paket.  
Det är byggt för att vara enkelt att konsumera från andra projekt – utan att du behöver checka in framework‑kod i din applikation.

## Installation

I en konsumerande applikation:

```bash
composer require mattablues/radix-framework
```
Ramverket kräver:

- PHP **8.3** eller senare inom 8.x‑serien
- Extension: `ext-libxml` (för XML‑funktionalitet)

## Snabbstart

Ett enkelt exempel på hur Radix kan användas (anpassa efter hur du faktiskt använder det i dina appar):

```php
<?php

declare(strict_types=1);

use Radix\File\Writer;
use Radix\File\Reader;

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

Det här är en snabb översikt – för en komplett, fungerande app, se
[`mattablues/radix-app`](https://github.com/mattablues/radix-app).

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
  - Migrationer + CLI‑stöd för att skapa och köra migrationer (`Radix\Database\Migration`)

- **Container & Service Providers**
  - Dependency Injection‑container (`Radix\Container\Container`, `ApplicationContainer`)
  - Definitioner, parametrar och referenser (`Definition`, `Parameter`, `Reference`, `Resolver`)
  - Service providers för att registrera tjänster (`Radix\ServiceProvider`)

- **View‑motor**
  - Template‑engine med ratio‑templates, extends/yield, komponenter och slots (`Radix\Viewer\RadixTemplateViewer`)
  - Cache‑hantering för vyer och enkla filter

- **Session & autentiseringsstöd**
  - Anpassningsbar sessionshanterare (`Radix\Session\RadixSessionHandler`) med fil- eller databaserad lagring

- **Fil, dataformat & utilities**
  - Fil‑ och data‑hjälpare (`Radix\File`), t.ex. CSV/NDJSON/XML/JSON‑läsning och skrivning
  - Datum/tid‑hjälpare (`Radix\DateTime`)
  - Stödklasser (`Radix\Support`), collections (`Radix\Collection`), enums (`Radix\Enums`)

- **Övrigt**
  - Event dispatcher (`Radix\EventDispatcher`)
  - Mailer‑stöd (`Radix\Mailer`)
  - Felhantering och fel‑svar (`Radix\Error`)

Ett typiskt flöde i en applikation som använder Radix kan vara:

- Skriva och läsa **CSV** (inkl. autodetekterad delimiter, streaming, icke‑skalära värden → JSON, osv.).
- Skriva och läsa **NDJSON** (newline‑delimited JSON) effektivt som stream.
- Skriva och läsa **XML** med kontrollerad encoding, libxml‑felhantering och assoc‑lägen.

---

## Projektlayout

Ramverket lever i ett eget repo och är tänkt att konsumeras via Composer:

- `mattablues/radix-framework` (Packagist‑paketet, källkod i [mattablues/radix-framework](https://github.com/mattablues/radix-framework)) innehåller själva ramverket.
- Applikationer (t.ex. [`mattablues/radix-app`](https://github.com/mattablues/radix-app) eller ditt eget projekt) lägger till ramverket via:

  ```bash
  composer require mattablues/radix-framework
  ```

Ingen framework‑kod ska checkas in direkt i app‑repon.

---

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

---

## För ramverksutvecklare

Det här avsnittet är framför allt för dig som utvecklar **själva Radix Framework** (inte bara använder det som beroende).

### Lokalt dev‑setup

I `mattablues/radix-framework`‑repon:

```bash
composer install
```

Några viktiga `composer`‑kommandon:

```bash
# Snabb testrunda (PHPUnit, utan coverage)
composer test

# Statisk analys (PHPStan)
composer stan

# Mutationstester (Infection)
composer infect
composer infect:pcov
composer infect:xdebug

# Kodstil (PHP-CS-Fixer)
composer format
composer format:check
```

Direkt från `composer.json`:

```json
"scripts": {
  "stan": "phpstan analyse -c phpstan.neon.dist",
  "test": "php -d xdebug.mode=off vendor/bin/phpunit",
  "infect": "php tools/infection.php",
  "infect:pcov": "php tools/infection.php pcov",
  "infect:xdebug": "php tools/infection.php xdebug",
  "format": "PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix",
  "format:check": "PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --dry-run --diff"
}
```

### Tester (PHPUnit)

Ramverket använder PHPUnit 11.  
Konfigurationen finns i `phpunit.xml`. Kör alla tester med:

```bash
composer test
```

Coverage är konfigurerat mot `src/` och används bl.a. för mutationstester.

### Statisk analys (PHPStan)

Regler och nivåer styrs av `phpstan.neon.dist`.

```bash
composer stan
```

### Mutationstester (Infection)

Infection 0.32 används för att säkerställa hög testkvalitet.

1. Generera coverage:

   ```bash
   rm -rf build/coverage
   mkdir -p build/coverage

   vendor/bin/phpunit \
     -c phpunit.xml \
     --order-by=default \
     --do-not-cache-result \
     --coverage-xml=build/coverage/coverage-xml \
     --log-junit=build/coverage/junit.xml
   ```

2. Kör Infection:

   ```bash
   composer infect
   # eller
   composer infect:pcov
   composer infect:xdebug
   ```

Inställningar finns i `infection.json.dist`.

### Kodstil (PHP-CS-Fixer)

PHP-CS-Fixer konfigureras i `.php-cs-fixer.dist.php` och kör mot:

- `src/`
- `tests/`

```bash
# Auto-fix
composer format

# Endast kontroll (används i CI)
composer format:check
```

`PHP_CS_FIXER_IGNORE_ENV=1` används för att slippa varningar om okänd PHP‑version.

### CI (GitHub Actions)

Repo:t innehåller GitHub Actions‑workflows som kör:

- Kodstil (`composer format:check`)
- Tester (`composer test`)
- PHPStan (`composer stan`)
- Schemalagd Infection‑körning som laddar upp html/text/JSON‑rapporter.

Detaljerna finns i `.github/workflows/*.yml` om du vill justera pipelines.

---

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

Nya versioner läggs upp genom att skapa nya taggar (`v0.2.0`, `v1.0.0`, …) och låta Packagist plocka upp dem via GitHub‑hooken.

---

## Licens

Radix Framework är licensierat under **MIT**.  
Se `LICENSE` för full licenstext.
