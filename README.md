# Plataforma web de gestión de proveedores y validación documental

Trabajo de título — Ingeniería Civil Informática y Telecomunicaciones, Universidad Finis Terrae.
Autor: Martín Olivos. Profesor guía: Jorge Bozo.

Propuesta de una plataforma web para la gestión de proveedores y validación documental, con un diseño de integración con SAP en un holding empresarial.

## Contenido del repositorio

| Carpeta | Descripción |
|---|---|
| `api/` | API REST (Lumen 10 / PHP), prefijo `/v1`. Base de datos MySQL `portal_proveedores`, pruebas PHPUnit y script de punta a punta. |
| `frontend/` | Aplicación web (React 19, Vite, TypeScript, Ant Design). |

La integración con SAP, DICOM y AMLUpdate está **simulada** (diseño de integración), tal como se describe en el informe.

## Cómo ejecutarlo en local

Requisitos: PHP 8.x con Composer, MySQL, Node.js y Mailpit (para ver los correos de prueba).

1. **API:** en `api/` copiar `.env.example` a `.env`, completar los datos de MySQL y ejecutar `composer install`.
2. **Datos de demostración:** `api/iniciar_demo.bat` prepara la base y carga los proveedores de ejemplo.
3. **Frontend:** en `frontend/` copiar `.env.example` a `.env`, ejecutar `npm install` y `npm run dev`.
4. Abrir `http://localhost:5173`. Los correos de prueba se ven en Mailpit (`http://localhost:8025`).

## Pruebas

Desde `api/`:

```
php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit
php tests\manual\e2e_proceso.php .
```

Los resultados de la última ejecución están en `evidencias/` (PHPUnit: 54 pruebas, 200 aserciones; prueba de punta a punta: 48 verificaciones). PHPUnit usa SQLite en memoria; la prueba de punta a punta corre contra MySQL dentro de una transacción que se revierte.

## Seguridad

El repositorio no incluye archivos `.env` ni credenciales. Los datos de demostración son ficticios.
