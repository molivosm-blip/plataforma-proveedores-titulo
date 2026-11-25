# Evidencias de pruebas

Salidas generadas el 25-11-2026 ejecutando las pruebas sobre el código de este repositorio, en el equipo del autor (PHP 8.2, MySQL con los 100 proveedores de demostración cargados).

| Archivo | Comando (desde `api/`) | Resultado |
|---|---|---|
| `salida_phpunit.txt` | `php -d extension=pdo_sqlite -d extension=sqlite3 vendor\phpunit\phpunit\phpunit` | OK (54 pruebas, 200 aserciones). Usan SQLite en memoria. |
| `salida_e2e.txt` | `php tests\manual\e2e_proceso.php .` | 48 verificaciones pasaron, 0 fallaron. Corre contra MySQL dentro de una transacción que se revierte al final: la base queda igual. |

En `salida_e2e.txt` aparecen avisos "Correo no enviado por SMTP, queda en el registro": ocurren cuando Mailpit no está encendido durante la prueba; el aviso queda guardado en el registro y la verificación correspondiente igual pasa.
