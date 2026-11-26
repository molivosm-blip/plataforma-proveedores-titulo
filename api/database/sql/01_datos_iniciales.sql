-- Datos mínimos para que el portal funcione: áreas de validación.
-- Se puede ejecutar más de una vez (no duplica áreas).
USE `portal_proveedores`;

INSERT IGNORE INTO `areas` (`code`, `name`, `active`, `created_at`, `updated_at`) VALUES
  ('pm',               'PM (Solicitante)',  1, NOW(), NOW()),
  ('contraloria',      'Contraloría',       1, NOW(), NOW()),
  ('legal_crediticia', 'Legal Crediticia',  1, NOW(), NOW()),
  ('legal_judicial',   'Legal Judicial',    1, NOW(), NOW()),
  ('datos_maestros',   'Datos Maestros',    1, NOW(), NOW());
