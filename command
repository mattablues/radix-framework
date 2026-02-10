# 1) XDEBUG för coverage
$env:XDEBUG_MODE = 'coverage'

# 2) Rensa ev gammal coverage och generera ny
Remove-Item -Recurse -Force .\build\coverage -ErrorAction SilentlyContinue
mkdir .\build\coverage | Out-Null

php vendor/bin/phpunit `
  -c phpunit.xml `
  --order-by=default `
  --do-not-cache-result `
  --coverage-xml=build/coverage/coverage-xml `
  --log-junit=build/coverage/junit.xml

# 3) Kör Infection på samma sätt som schedule (ev. med filter när du felsöker)
$env:XDEBUG_MODE = 'coverage'
$env:APP_ENV = 'development'

php vendor/bin/infection `
  --configuration=infection.json.dist `
  --threads=1 `
  --skip-initial-tests `
  --coverage=build/coverage `
  --filter=src/File/Writer.php `
  --show-mutations `
  --log-verbosity=all `
  --no-interaction `
  --no-progress


  ------------------------------
$env:XDEBUG_MODE = 'coverage'

# (om du inte redan har färsk coverage)
Remove-Item -Recurse -Force .\build\coverage -ErrorAction SilentlyContinue
mkdir .\build\coverage | Out-Null

php vendor/bin/phpunit `
  -c phpunit.xml `
  --order-by=default `
  --do-not-cache-result `
  --coverage-xml=build/coverage/coverage-xml `
  --log-junit=build/coverage/junit.xml

$env:XDEBUG_MODE = 'coverage'
$env:APP_ENV = 'development'
$tmp = Join-Path $env:TEMP "infection-tmp"
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

php vendor/bin/infection `
  --configuration=infection.json.dist `
  --threads=1 `
  --skip-initial-tests `
  --coverage=build/coverage `
  --filter=src/File/Writer.php `
  --log-verbosity=all `
  --no-interaction `
  --no-progress
