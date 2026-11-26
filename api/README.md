# API del portal de proveedores

API REST del trabajo de título «Plataforma web de gestión de proveedores y validación documental».
Construida con Lumen 10 (PHP 8.x), prefijo `/v1`, autenticación con JWT (HS256) y base de datos MySQL `portal_proveedores`.

## Qué hace

- Autenticación de dos tipos de usuario: **proveedores** (correo y clave, con invitación previa) e **internos** (Microsoft Entra ID).
- Flujo de alta de proveedores: invitación, ficha, documentos, validación por áreas, aprobación y envío a SAP (simulado).
- Consultas de riesgo: Gesintel/AMLUpdate (ambiente QA real) y DICOM (real o simulado).
- Notificaciones por correo y en el portal, recordatorios y avisos de vencimiento de documentos.
- Roles: `proveedor`, `pm_user`, `pm_admin`, `admin_portal`, `admin_area`, `usuario_area` y `auditor` (solo lectura).

## Requisitos

PHP 8.1 o superior con las extensiones `curl`, `mbstring`, `openssl`, `pdo_mysql` y `zip`; Composer; MySQL 8 (o MariaDB 10.6+); Mailpit para ver los correos de prueba.

## Puesta en marcha

```
composer install
copy .env.example .env        (Linux/macOS: cp .env.example .env)
# completar DB_*, JWT_SECRET y APP_KEY en .env
php artisan mejoras:aplicar   # crea/ajusta las tablas y columnas nuevas (idempotente)
php -S localhost:8000 -t public
```

`iniciar_demo.bat` hace estos pasos en Windows y carga los proveedores de demostración.
Las tablas base están en `database/sql/`; los datos de demostración, en `database/demo/` y `database/seeders/`.

Comprobación rápida: `GET http://localhost:8000/health/database` responde que la conexión con MySQL está operativa, sin revelar el nombre de la base.

## Variables de entorno

Todas están documentadas en `.env.example`. Las más importantes:

| Variable | Para qué sirve | Valor por defecto |
|---|---|---|
| `APP_DEBUG` | `false` oculta los detalles técnicos de los errores 500 | `false` |
| `DB_*` | Conexión a MySQL | — |
| `JWT_SECRET` | Clave de firma de los tokens. Larga y aleatoria; nunca compartirla | — |
| `JWT_TTL_SECONDS` | Duración de la sesión | `3600` |
| `LOGIN_MAX_INTENTOS`, `LOGIN_BLOQUEO_MINUTOS` | Bloqueo por intentos fallidos | `5` y `15` |
| `PASSWORD_BCRYPT_COST` | Costo del hash de claves | `12` |
| `RECUPERACION_VIGENCIA_MINUTOS`, `RECUPERACION_MAX_POR_HORA` | Recuperación de clave | `60` y `3` |
| `RECORDATORIO_DOCUMENTOS_DIAS` | Cada cuántos días se recuerda al proveedor un documento pendiente | `3` |
| `DOCUMENTO_AVISO_DIAS` | Anticipación del aviso de documento por vencer | `30` |
| `WORKFLOW_PLAZO_DIAS` | Plazo máximo por etapa del workflow | `3` |
| `REEVALUACION_MESES` | Frecuencia de re-evaluación de proveedores aprobados | `12` |
| `DICOM_MODO` | `real` o `simulado` | `real` |
| `DICOM_BASE_URL`, `DICOM_TIMEOUT` | Servicio DICOM real | — |
| `AMLUPDATE_*` | Gesintel/AMLUpdate (solo HTTPS) | — |
| `SAP_MOCK_FORZAR_ERROR` | `true` simula una caída de SAP | `false` |
| `MAIL_MODO` | `smtp` envía; `log` escribe en `storage/logs/correos_simulados.log` | `log` |
| `PORTAL_URL` | URL del frontend usada en los enlaces de los correos | — |

## Tareas programadas

`app/Console/Kernel.php` agenda los comandos siguientes. Se ejecutan con el programador de tareas del sistema (cron o Programador de tareas de Windows) llamando cada minuto a `php artisan schedule:run`.

| Comando | Cuándo | Efecto |
|---|---|---|
| `plazos:revisar` | Diario 08:30 | Revisa los plazos de las etapas del workflow |
| `documentos:vencimientos` | Diario 08:45 | Avisa documentos por vencer o vencidos |
| `proveedores:recordar` | Diario 09:00 | Recuerda a los proveedores sus documentos pendientes |
| `proveedores:reevaluar` | Día 1 de cada mes, 07:00 | Marca proveedores para re-evaluación |

Comandos manuales: `mejoras:aplicar` (ajustes de esquema, idempotente), `estados:reconciliar [--aplicar]`, `seguridad:aplicar` y `cuenta:aplicar`.

## Pruebas

```
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit
php tests/manual/e2e_proceso.php .
php tests/manual/carga.php http://localhost:8000/v1
```

- **PHPUnit** (SQLite en memoria): reglas de negocio, RUT, recuperación de clave, rol auditor, ataques directos a la API (sin token, token manipulado o vencido, roles equivocados, inyección SQL, subidas maliciosas, fuga de información) y resiliencia ante caída de Gesintel, DICOM o SAP usando un servidor externo falso (`tests/herramientas/servidor_falso.php`).
- **Punta a punta** (`e2e_proceso.php`): recorre todo el proceso contra MySQL dentro de una transacción que se revierte al final.
- **Carga** (`carga.php`): lanza usuarios concurrentes contra la API en ejecución y reporta tiempos y errores. Usar con un servidor que atienda en paralelo; `php -S` es de un solo hilo y subestima el rendimiento.

## Estructura

```
app/Http/Controllers   Controladores por recurso
app/Http/Middleware    Jwt y Role (incluye el modo solo lectura del auditor)
app/Services           Reglas de negocio e integraciones (Auth, RUT, DICOM, AML, SAP simulado, correo)
app/Console            Comandos y programación de tareas
routes/api.php         Todas las rutas /v1, agrupadas por rol
tests/                 Pruebas automáticas y manuales
database/              SQL base, datos de demostración y seeders
docker/                Plantilla antigua de Lumen (no se usa); la ejecución en contenedores está en ../docker-compose.yml
```

## Seguridad

- Claves con bcrypt y política de complejidad; bloqueo temporal por intentos fallidos; recuperación con token de un solo uso guardado como hash.
- Sesiones con JWT; un usuario desactivado pierde el acceso de inmediato.
- Los documentos se sirven solo con sesión y permiso sobre el proveedor.
- Los errores internos no revelan SQL, rutas ni trazas cuando `APP_DEBUG=false`.
- Las exportaciones a CSV neutralizan fórmulas (`=`, `+`, `-`, `@`).
- No subir `.env` al repositorio. Las credenciales de demostración son ficticias.

## Integraciones

El contrato de mensajes con SAP y DICOM está en `../docs/INTEGRACION_SAP_DICOM.md`.
