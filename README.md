# Plataforma web de gestión de proveedores y validación documental

Trabajo de título — Ingeniería Civil Informática y Telecomunicaciones, Universidad Finis Terrae.
Autor: Martín Olivos. Profesor guía: Jorge Bozo.

Propuesta de una plataforma web para la gestión de proveedores y validación documental, con un diseño de integración con SAP en un holding empresarial.

## Contenido del repositorio

| Carpeta | Descripción |
|---|---|
| `api/` | API REST (Lumen 10 / PHP), prefijo `/v1`. Base de datos MySQL `portal_proveedores`, pruebas PHPUnit y script de punta a punta. |
| `frontend/` | Aplicación web (React 19, Vite, TypeScript, Ant Design). |
| `docs/` | Manual de usuario por rol, contrato de integración con SAP y DICOM, y diagrama del proceso TO-BE. |
| `scripts/` | Respaldo y restauración de la base de datos y los documentos. |
| `evidencias/` | Salidas de las pruebas (PHPUnit, punta a punta, carga, frontend). |

La integración con SAP, DICOM y AMLUpdate está **simulada** (diseño de integración), tal como se describe en el informe.

## Cómo ejecutarlo en local

Requisitos: PHP 8.x con Composer, MySQL, Node.js y Mailpit (para ver los correos de prueba).

1. **API:** en `api/` copiar `.env.example` a `.env`, completar los datos de MySQL y ejecutar `composer install`.
2. **Datos de demostración:** `api/iniciar_demo.bat` prepara la base y carga los proveedores de ejemplo.
3. **Frontend:** en `frontend/` copiar `.env.example` a `.env`, ejecutar `npm install` y `npm run dev`.
4. Abrir `http://localhost:5173`. Los correos de prueba se ven en Mailpit (`http://localhost:8025`).

### Con Docker

`docker-compose.yml` levanta MySQL, API, frontend, Mailpit y las tareas programadas. Copiar `.env.docker.example` a `.env`, completar las claves y ejecutar `docker compose up --build`. La base se crea con los archivos de `api/database/sql/`; después, para dejarla al día y registrar al primer administrador:

```
docker compose exec api php artisan cuenta:aplicar
docker compose exec api php artisan seguridad:aplicar
docker compose exec api php artisan mejoras:aplicar
docker compose exec api php artisan usuarios:crear-interno correo@empresa.cl "Nombre Apellido" admin_portal
```

La configuración de contenedores está en modo demostración (ver comentarios en el archivo).

## Documentación

- [Manual de usuario por rol](docs/MANUAL_USUARIO.md)
- [Contrato de integración con SAP y DICOM](docs/INTEGRACION_SAP_DICOM.md)
- [README de la API](api/README.md): variables de entorno, tareas programadas y estructura.

## Respaldo

`scripts/respaldo.sh` genera un volcado comprimido de la base y un archivo con los documentos subidos, verifica su integridad y borra los antiguos (14 días por defecto). `scripts/restaurar.sh archivo.sql.gz [base_destino]` lo restaura, idealmente en una base distinta para comprobarlo. Requiere `mysqldump` y `mysql` en el PATH (en Windows, Git Bash o WSL).

## Integración continua

`.github/workflows/ci.yml` ejecuta en cada cambio PHPUnit (API) y verificación de tipos, lint y build (frontend).

## Pruebas

Desde `api/`:

```
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit
php tests\manual\e2e_proceso.php .
php tests\manual\carga.php . http://localhost:8000 1,10,25,50 100   # con la API encendida
```

Desde `frontend/`: `npx tsc -b`, `npx eslint .` y `npm run build`.

Los resultados de la última ejecución están en `evidencias/` (PHPUnit: 101 pruebas, 1.254 aserciones; prueba de punta a punta: 49 verificaciones; prueba de carga: 400 solicitudes sin errores; frontend: tipos, lint y build sin errores). PHPUnit usa SQLite en memoria; la prueba de punta a punta corre contra MySQL dentro de una transacción que se revierte.

## Seguridad

El repositorio no incluye archivos `.env` ni credenciales. Los datos de demostración son ficticios.
