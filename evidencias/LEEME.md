# Evidencias de pruebas

Salidas generadas el 26-11-2026 ejecutando las pruebas sobre el código de este repositorio, en el equipo del autor (Windows, PHP 8.2, MySQL con 102 proveedores de demostración cargados).

| Archivo | Comando (desde `api/`) | Resultado |
|---|---|---|
| `salida_phpunit.txt` | `php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit` | OK: 101 pruebas, 1.254 aserciones. Usan SQLite en memoria. Incluyen ataques directos a la API (rutas sin sesión, rol equivocado, acceso a datos de otro proveedor o área, inyección SQL, peticiones mal formadas, intento de elevar el propio rol) y resiliencia ante caídas, lentitud y respuestas basura de Gesintel y DICOM, y fallas de base de datos y correo. |
| `salida_e2e.txt` | `php tests\manual\e2e_proceso.php .` | 49 verificaciones pasaron, 0 fallaron. Corre contra MySQL dentro de una transacción que se revierte al final: la base queda igual. |
| `salida_carga.txt` | `php tests\manual\carga.php . http://localhost:8000 1,10,25,50 100` | 400 solicitudes de lectura con 1, 10, 25 y 50 usuarios simultáneos: 0 errores. Ver nota. |
| `salida_frontend.txt` | `npx tsc -b`, `npx eslint .`, `npm run build` (desde `frontend/`) | Los tres terminan con código 0. |

## Notas

- **Prueba de carga:** la API de la demostración corre con el servidor de desarrollo de PHP (`php -S`), que atiende **una solicitud a la vez**; por eso el tiempo de respuesta crece de forma lineal con los usuarios simultáneos (a 50 usuarios la mediana es de unos 10 s en el equipo del autor). Lo relevante es que no hubo errores ni caídas. En producción se usa PHP-FPM con nginx, que atiende varias solicitudes en paralelo, así que estos tiempos son un techo pesimista y no una medición de producción.
- En `salida_e2e.txt` aparecen avisos "Correo no enviado por SMTP, queda en el registro": ocurren cuando Mailpit no está encendido durante la prueba; el aviso queda guardado en el registro y la verificación correspondiente igual pasa.
- En `salida_phpunit.txt` aparecen mensajes de error esperados (por ejemplo "no such table", "AMLUpdate no respondió"): son pruebas que provocan esas fallas a propósito para comprobar que el portal responde de forma controlada.
- El aviso de tamaño de bloque (>500 kB) en `salida_frontend.txt` es una advertencia de rendimiento de Vite, no un error.
