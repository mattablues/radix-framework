# Radix Framework

Radix Framework är ett fristående PHP‑ramverk designat för att användas som ett Composer‑paket.

Målet är att:

- Ramverket lever i ett eget repo (`mattablues/radix-framework`).
- Applikationer (t.ex. `radix-app`) konsumerar ramverket via Composer.
- Ingen framework‑kod checkas in direkt i app‑repon.

---

## Installation

I en konsumerande applikation:
```
bash composer require radix/framework
```

I själva `radix-framework`‑repon:
```
bash composer install
```

---

## Krav

- PHP: **8.3** (CI kör 8.3; lokalt kan du köra nyare, t.ex. 8.4, men se noteringen om PHP-CS-Fixer nedan)
- Extensions:
  - `ext-libxml` (för XML‑funktionalitet)

---

## Autoloading

Ramverket följer PSR‑4:
```
json "autoload": { "psr-4": { "Radix\": "src/" }, "files": }
```

Tester autoloadas via:
```
json "autoload-dev": { "psr-4": { "Radix\Tests\": "tests/" } }
```

---

## Composer‑scripts

Några viktiga `composer`‑kommandon från repo‑roten:
```bash
Snabb testrunda (PHPUnit, utan coverage)
composer test
PHPUnit med enkel coverage‑output (text, Xdebug)
composer test:cov
Generera XML‑coverage till build/coverage (pcov) – används av Infection
composer test:coverage
Statisk analys (PHPStan)
composer stan
Mutationstester (Infection) mot build/coverage
composer infect
# standard (APP_ENV=development)
composer infect:pcov
# explicit pcov-scenario
composer infect:xdebug
# explicit Xdebug-scenario
Kodstil (PHP-CS-Fixer)
composer format
# auto‑fixar kodstil
composer format:check
# bara kontroll (failar om något behöver fixas)
```

Kortfattad översikt av scripts (ur `composer.json`):
```json
"scripts": {
  "stan": "phpstan analyse -c phpstan.neon.dist",
  "test": "php -d xdebug.mode=off vendor/bin/phpunit",
  "test:cov": "php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text",
  "test:coverage": "php -d pcov.enabled=1 -d pcov.directory=src vendor/bin/phpunit -c phpunit.xml --order-by=default --do-not-cache-result --coverage-xml=build/coverage/coverage-xml --log-junit=build/coverage/junit.xml",
  "infect:pcov": "APP_ENV=development php vendor/bin/infection --configuration=infection.json.dist --threads=1 --skip-initial-tests --coverage=build/coverage --log-verbosity=all --no-interaction --no-progress",
  "infect:xdebug": "XDEBUG_MODE=coverage APP_ENV=development php vendor/bin/infection --configuration=infection.json.dist --threads=1 --skip-initial-tests --coverage=build/coverage --log-verbosity=all --no-interaction --no-progress",
  "infect": "APP_ENV=development php vendor/bin/infection --configuration=infection.json.dist --threads=1 --skip-initial-tests --coverage=build/coverage --log-verbosity=all --no-interaction --no-progress",
  "format": "PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix",
  "format:check": "PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --dry-run --diff"
}
```

---

## Tester (PHPUnit)

Ramverket använder PHPUnit 11.

Konfiguration finns i `phpunit.xml`:

- Bootstrap: `tests/bootstrap.php`
- Testsuite `framework`:

  ```xml
  <testsuite name="framework">
    <directory>tests</directory>
  </testsuite>
  ```

- Source för coverage:

  ```xml
  <source>
    <include>
      <directory>src</directory>
    </include>
    <exclude>
      <directory>../vendor</directory>
      <directory>tests</directory>
    </exclude>
  </source>
  ```

- Miljö:

  ```xml
  <php>
    <ini name="error_reporting" value="-1"/>
    <ini name="display_errors" value="1"/>
    <env name="APP_ENV" value="testing"/>
  </php>
  ```

Kör alla tester:
```
bash composer test
```

---

## Statisk analys (PHPStan)

Regler och nivåer styrs av `phpstan.neon.dist`.

Kör analys:
```
bash composer stan
```

---

## Mutationstester (Infection)

Mutationstester körs med Infection 0.32 och använder förgenererad coverage.

### 1. Generera coverage
```
bash rm -rf build/coverage && mkdir -p build/coverage composer test:coverage
```

Det ger:

- `build/coverage/coverage-xml/` (Clover/XML)
- `build/coverage/junit.xml`

### 2. Kör Infection

Standard:
```
bash composer infect
```

Andra varianter:
```
bash composer infect:pcov composer infect:xdebug
```

Parametrarna är definierade i `composer.json` och konfigurationen finns i `infection.json.dist`.  
Mutatorerna är aktiva mot `src/` och konfigurerade för att ge 100 % mutation coverage i ramverket.

---

## Kodstil (PHP-CS-Fixer)

Kodstilen hanteras av PHP-CS-Fixer med konfiguration i `.php-cs-fixer.dist.php`.

- Omfattar:
  - `src/`
  - `tests/`
- Baseras på `@PSR12` plus ett urval extra regler (import‑ordning, docblock‑städning, m.m.).
- Risky‑regler är tillåtna (`setRiskyAllowed(true)`), men reglerna är valda med försiktighet.

### Körning

Auto‑fix:
```
bash composer format
```

Kontroll (används i CI):
```
bash composer format:check
```

> **Notering (PHP 8.4 lokalt):**  
> Vissa versioner av PHP-CS-Fixer varnar för okänd PHP‑version.  
> Därför sätter scriptsen `PHP_CS_FIXER_IGNORE_ENV=1` för att tysta miljövarningen.

---

## CI (GitHub Actions)

### Vanlig CI (`.github/workflows/ci.yml`)

Körs på:
```
yaml on: push: pull_request:
```

Miljö:

- `runs-on: ubuntu-latest`
- PHP 8.3 via `shivammathur/setup-php`

Steg:

1. Checkout (`actions/checkout@v4`)
2. Installera beroenden (`composer install`)
3. Kodstilskontroll:

   ```yaml
   - name: Check code style
     run: composer format:check
   ```

4. PHPUnit:

   ```yaml
   - name: Run PHPUnit
     run: composer test
   ```

5. PHPStan:

   ```yaml
   - name: Run PHPStan
     run: composer stan
   ```

Composer‑cache används för att snabba upp installationer.

### Schemalagd Infection (`.github/workflows/infection-schedule.yml`)

Körs:
```
yaml on: schedule: - cron: "0 6 * * 1" # måndagar 06:00 UTC workflow_dispatch:
```

Flöde:

1. Installerar beroenden.
2. Pre‑check med PHPUnit (utan coverage‑output till Infection).
3. Genererar coverage:

   ```bash
   rm -rf build/coverage || true
   mkdir -p build/coverage
   vendor/bin/phpunit \
     -c phpunit.xml \
     --order-by=default \
     --do-not-cache-result \
     --coverage-xml=build/coverage/coverage-xml \
     --log-junit=build/coverage/junit.xml
   ```

4. Rensar ev. Infection‑cache.
5. Kör Infection:

   ```bash
   php vendor/bin/infection \
     --configuration=infection.json.dist \
     --threads=1 \
     --skip-initial-tests \
     --coverage=build/coverage \
     --logger-html=infection-report.html \
     --logger-text=infection-report.txt \
     --logger-summary-json=infection-summary.json \
     --log-verbosity=all \
     --no-interaction \
     --no-progress
   ```

6. Laddar upp rapporter som artifacts.

Env i jobbet:
```
yaml env: RADIX_RUN_ID: "{{ github.run_id }}-{{ github.run_attempt }}"
```

Detta används i cache‑nyckelgenerering för att undvika krockar mellan olika CI‑körningar.

---

## Versionering och releases

Rekommenderat första steg:

1. Bestäm första version: t.ex. `v0.1.0`.
2. Tagga och pusha:

   ```bash
   git tag v0.1.0
   git push --tags
   ```

3. I konsumerande app (t.ex. `radix-app`):

   ```bash
   composer require radix/framework:^0.1
   ```

---

## Målstatus

Ramverket anses “grundklart” när:

- CI‑workflowen (`ci.yml`) är grön på `push` och `pull_request`.
- Schemalagd Infection‑workflow kan köras manuellt (`workflow_dispatch`) och laddar upp rapporter.
- En extern app kan installera `radix/framework` via Composer utan specialhack.

---

## Licens

Radix Framework är licensierat under **MIT**.  
Se `LICENSE` för full licenstext.
