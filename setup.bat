@echo off
REM One-time setup for the whole SOA system (Windows).
REM Requirements: PHP 8.3+, Composer, Node 20+, MySQL running with the 3 databases (create_databases.sql).
setlocal
set ROOT=%~dp0

for %%S in (auth-service catalog-service enrollment-service) do (
    echo.
    echo ===== %%S =====
    pushd "%ROOT%services\%%S"
    call composer install --no-interaction
    if not exist .env copy .env.example .env >nul
    call php artisan key:generate --force
    call php artisan migrate:fresh --seed --force
    popd
)

echo.
echo ===== gateway (NestJS) =====
pushd "%ROOT%gateway"
if not exist .env copy .env.example .env >nul
call npm install
popd

echo.
echo ===== web (React) =====
pushd "%ROOT%web"
if not exist .env copy .env.example .env >nul
call npm install
popd

echo.
echo Listo. Ejecuta start-all.bat
endlocal
