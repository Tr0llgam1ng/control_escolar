@echo off
REM Starts the 3 Laravel services, the NestJS gateway and the React app, each in its own window.
set ROOT=%~dp0
start "Servicio 1 - Auth :8001"         cmd /k "cd /d %ROOT%services\auth-service && php artisan serve --port=8001"
start "Servicio 2 - Catalogo :8002"     cmd /k "cd /d %ROOT%services\catalog-service && php artisan serve --port=8002"
start "Servicio 3 - Inscripciones :8003" cmd /k "cd /d %ROOT%services\enrollment-service && php artisan serve --port=8003"
start "API Gateway NestJS :3000"        cmd /k "cd /d %ROOT%gateway && npm run start:dev"
start "App React :5173"                 cmd /k "cd /d %ROOT%web && npm run dev"
echo Abre http://localhost:5173
