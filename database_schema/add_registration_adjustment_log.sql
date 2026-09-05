-- Idempotent offline adjustment sync log
CREATE TABLE IF NOT EXISTS `evac_registration_adjustment_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_adjustment_uuid` varchar(36) NOT NULL,
  `registration_id` int(10) UNSIGNED NOT NULL,
  `center_id` int(10) UNSIGNED NOT NULL,
  `field_name` varchar(64) NOT NULL,
  `delta` tinyint(4) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_adjustment_uuid` (`client_adjustment_uuid`),
  KEY `idx_adj_registration` (`registration_id`),
  KEY `idx_adj_center` (`center_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
