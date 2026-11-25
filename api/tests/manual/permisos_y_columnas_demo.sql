-- Ejecutar UNA SOLA VEZ con un usuario administrador de MySQL (root), no con portal_user.
-- Crea las columnas y la tabla que necesita la API (Mi cuenta y bloqueo de login).
-- Es idempotente: si algo ya existe, no lo vuelve a crear.
USE portal_proveedores;

DROP PROCEDURE IF EXISTS _demo_agregar_columna;
DELIMITER //
CREATE PROCEDURE _demo_agregar_columna(IN tabla VARCHAR(64), IN columna VARCHAR(64), IN definicion VARCHAR(200))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tabla AND COLUMN_NAME = columna) THEN
    SET @sql = CONCAT('ALTER TABLE `', tabla, '` ADD COLUMN `', columna, '` ', definicion);
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END//
DELIMITER ;

CALL _demo_agregar_columna('users', 'ultimo_ingreso_at',   'DATETIME NULL');
CALL _demo_agregar_columna('users', 'ingreso_anterior_at', 'DATETIME NULL');
CALL _demo_agregar_columna('users', 'intentos_fallidos',   'INT UNSIGNED NOT NULL DEFAULT 0');
CALL _demo_agregar_columna('users', 'bloqueado_hasta',     'DATETIME NULL');
DROP PROCEDURE _demo_agregar_columna;

CREATE TABLE IF NOT EXISTS usuario_preferencias_notificacion (
  user_id BIGINT UNSIGNED NOT NULL,
  grupo VARCHAR(30) NOT NULL,
  correo TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NULL,
  PRIMARY KEY (user_id, grupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SELECT 'Listo: ahora vuelve a ejecutar iniciar_demo.bat' AS resultado;
