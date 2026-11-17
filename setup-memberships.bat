@echo off
REM Adds memberships (Servicio 5) to an installation that already works, WITHOUT erasing data.
REM 1) Run create_databases.sql first (it only creates soa_memberships if it is missing).
REM 2) Run this file. 3) Run start-all.bat
setlocal
set ROOT=%~dp0

echo.
echo ===== payments-service: credit packs (new column + seed) =====
pushd "%ROOT%services\payments-service"
call php artisan migrate --force
call php artisan db:seed --force
REM memberships-service may read the status of a reference (internal endpoint): add it to PAYMENTS_INTERNAL_CALLERS
powershell -NoProfile -Command "$f='.env'; $c=Get-Content $f; $c=$c -replace '^(PAYMENTS_INTERNAL_CALLERS=(?!.*memberships-service).*)$','$1,memberships-service'; Set-Content $f $c"
popd

echo.
echo ===== memberships-service (Servicio 5, :8005) =====
pushd "%ROOT%services\memberships-service"
call composer install --no-interaction
if not exist .env copy .env.example .env >nul
REM Same SERVICE_KEY as payments-service
powershell -NoProfile -Command "$k=(Select-String -Path '..\payments-service\.env' -Pattern '^SERVICE_KEY=').Line; if ($k) { (Get-Content .env) -replace '^SERVICE_KEY=.*$',$k | Set-Content .env }"
call php artisan key:generate --force
call php artisan migrate --seed --force
popd

echo.
echo Listo. Ejecuta start-all.bat
endlocal
