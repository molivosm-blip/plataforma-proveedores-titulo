-- Portal de Proveedores: tablas para las funciones agregadas en noviembre de 2026.
-- Declaraciones digitales, evaluación crediticia, notificaciones y servicio simulado de SAP.
-- Se puede ejecutar más de una vez: no duplica tablas, empresas ni documentos.

USE `portal_proveedores`;

CREATE TABLE IF NOT EXISTS `proveedor_declaraciones` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `proveedor_id` bigint NOT NULL,
  `documento_id` bigint DEFAULT NULL,
  `tipo` enum('conflicto_interes','relacionados_pep') COLLATE utf8mb4_unicode_ci NOT NULL,
  `respuestas_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `declara_relacionados` tinyint(1) NOT NULL DEFAULT '0',
  `declara_pep` tinyint(1) NOT NULL DEFAULT '0',
  `declarante_nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `declarante_rut` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `declarante_cargo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `declarante_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firmado_por_user_id` bigint DEFAULT NULL,
  `firmado_at` datetime NOT NULL,
  `firma_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firma_user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `firma_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_declaraciones_proveedor_tipo` (`proveedor_id`,`tipo`),
  KEY `fk_declaraciones_documento` (`documento_id`),
  KEY `fk_declaraciones_user` (`firmado_por_user_id`),
  CONSTRAINT `fk_declaraciones_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_declaraciones_documento` FOREIGN KEY (`documento_id`) REFERENCES `proveedor_documentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_declaraciones_user` FOREIGN KEY (`firmado_por_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proveedor_evaluaciones_crediticias` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `proveedor_id` bigint NOT NULL,
  `resultado` enum('verde','amarillo','rojo') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reglas_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_inicio_actividades` date DEFAULT NULL,
  `dicom_resultado` enum('sin_morosidad','con_morosidad','no_consultado') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no_consultado',
  `observaciones` text COLLATE utf8mb4_unicode_ci,
  `origen` enum('manual','reevaluacion') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `informe_documento_id` bigint DEFAULT NULL,
  `created_by_user_id` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eval_proveedor_fecha` (`proveedor_id`,`created_at`),
  KEY `fk_eval_documento` (`informe_documento_id`),
  KEY `fk_eval_user` (`created_by_user_id`),
  CONSTRAINT `fk_eval_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_documento` FOREIGN KEY (`informe_documento_id`) REFERENCES `proveedor_documentos` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eval_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notificaciones` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `proveedor_id` bigint DEFAULT NULL,
  `tipo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `referencia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `titulo` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mensaje` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_enviado` tinyint(1) NOT NULL DEFAULT '0',
  `leida_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notificaciones_user_leida` (`user_id`,`leida_at`),
  KEY `idx_notificaciones_referencia` (`tipo`,`referencia`),
  KEY `fk_notificaciones_proveedor` (`proveedor_id`),
  CONSTRAINT `fk_notificaciones_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notificaciones_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proveedor_sap_envios` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `proveedor_id` bigint NOT NULL,
  `estado` enum('exitoso','observado','error') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `respuesta_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_proveedor_sap` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sap_proveedor` (`proveedor_id`),
  KEY `fk_sap_user` (`created_by_user_id`),
  CONSTRAINT `fk_sap_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sap_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Empresas del holding (sección 1.1 del documento).
INSERT IGNORE INTO `empresas` (`id`, `nombre`) VALUES
  ('DIMERC', 'Dimerc'),
  ('OFIMASTER', 'Ofimaster'),
  ('RHEIN', 'Rhein'),
  ('DIMEIGGS', 'Dimeiggs'),
  ('PRONOBEL', 'Pronobel');

-- Catálogo de documentos: cada documento tiene un área responsable,
-- que es la única que puede validarlo.
INSERT INTO `documento_requisitos`
  (`nombre`, `descripcion`, `tipo_proveedor_detalle`, `responsable_carga`, `area_responsable`,
   `es_prerrequisito`, `bloquea_carga_proveedor`, `visible_para_proveedor`, `visible_para_internos`,
   `plantilla_url`, `sort_order`, `active`)
SELECT n.* FROM (
  SELECT 'Declaración Conflicto de Interés Solicitante' AS nombre, 'Formulario digital firmado por el solicitante' AS descripcion, NULL AS tipo_proveedor_detalle, 'pm' AS responsable_carga, 'contraloria' AS area_responsable, 1 AS es_prerrequisito, 1 AS bloquea_carga_proveedor, 0 AS visible_para_proveedor, 1 AS visible_para_internos, NULL AS plantilla_url, 1 AS sort_order, 1 AS active
  UNION ALL SELECT 'Autorización de jefatura', 'Correo de aprobación de Gerencia o documento equivalente', NULL, 'pm', 'pm', 1, 1, 0, 1, NULL, 2, 1
  UNION ALL SELECT 'Referencia comercial', 'Al menos una referencia comercial', NULL, 'proveedor', 'legal_crediticia', 0, 0, 1, 1, NULL, 10, 1
  UNION ALL SELECT 'Declaración Relacionados y PEP', 'Formulario digital firmado por el representante legal', 'Proveedor de productos nacional', 'proveedor', 'contraloria', 0, 0, 1, 1, NULL, 11, 1
  UNION ALL SELECT 'Declaración Relacionados y PEP', 'Formulario digital firmado por el representante legal', 'Proveedor de productos internacional', 'proveedor', 'contraloria', 0, 0, 1, 1, NULL, 11, 1
  UNION ALL SELECT 'Poder de Representante Legal', 'Escritura o certificado de vigencia de poder', NULL, 'proveedor', 'legal_judicial', 0, 0, 1, 1, NULL, 12, 1
) AS n
WHERE NOT EXISTS (
  SELECT 1 FROM `documento_requisitos` d
  WHERE d.nombre = n.nombre COLLATE utf8mb4_unicode_ci
    AND d.tipo_proveedor_detalle <=> n.tipo_proveedor_detalle COLLATE utf8mb4_unicode_ci
);
