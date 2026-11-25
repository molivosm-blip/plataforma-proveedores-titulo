@echo off
chcp 65001 >nul
cd /d "%~dp0"
title Demo Portal de Proveedores - preparacion
echo ============================================================
echo  Demo Portal de Proveedores
echo  Requisito: MySQL encendido (la base portal_proveedores)
echo ============================================================
echo.
echo [1/6] Aplicando cambios de base de datos pendientes...
php artisan cuenta:aplicar
if errorlevel 1 goto error_bd
php artisan seguridad:aplicar
php artisan estados:reconciliar --aplicar
echo.
echo [2/6] Borrando los proveedores de demostracion anteriores (solo los que creo este script)...
php tests\manual\seed_demo.php . --limpiar
echo.
echo [3/6] Cargando 100 proveedores en todas las etapas, con documentos subidos (1 a 2 minutos)...
php tests\manual\seed_demo.php .
echo.
echo [4/6] Generando los PDF de ejemplo para subir en vivo...
php tests\manual\seed_demo.php . --paquete
echo.
echo [5/6] Encendiendo Mailpit (correos), la API y el frontend en ventanas nuevas...
start "Mailpit (http://localhost:8025)" cmd /k "cd /d %~dp0tools\mailpit && mailpit.exe"
start "API Portal (puerto 8000)" cmd /k "cd /d %~dp0 && php -S localhost:8000 -t public"
start "Frontend (puerto 5173)" cmd /k "cd /d %~dp0..\portal_proveedores\portal_proveedores && npm run dev"
echo.
echo [6/6] Abriendo el portal y la carpeta con los documentos de ejemplo...
timeout /t 8 >nul
start "" "http://localhost:5173"
start "" "%~dp0storage\app\demo_documentos_para_subir"
echo.
echo ============================================================
echo  Listo. No cierres las 3 ventanas nuevas mientras muestras la demo.
echo  Proveedores de demo: correo xxx@demo-proveedores.test  clave: Demo2026Portal!
echo  Lista completa: storage\app\demo_proveedores_resumen.json
echo ============================================================
pause
exit /b 0

:error_bd
echo.
echo ERROR: no se pudo preparar la base de datos.
echo Si el mensaje de arriba dice 'ALTER command denied', el usuario portal_user no puede crear columnas.
echo SOLUCION (una sola vez): abre tests\manual\permisos_y_columnas_demo.sql en DBeaver o MySQL Workbench
echo con tu conexion de administrador (root), ejecutalo completo y vuelve a ejecutar este archivo.
echo Si el mensaje es otro, enciende MySQL y vuelve a ejecutar este archivo.
pause
exit /b 1
