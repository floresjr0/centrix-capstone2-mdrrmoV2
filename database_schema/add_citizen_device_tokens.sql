-- Migration: optional Android fingerprint (device-token) login for citizens
-- Run manually against mdrrmo_db after review. Does NOT modify existing tables or data.
--
-- Creates citizen_device_tokens to store ONLY a bcrypt hash of a random device token.
-- No fingerprint images, templates, or biometric data are stored.

CREATE TABLE IF NOT EXISTS `citizen_device_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'FK to users.id (citizen account)',
  `device_id` varchar(64) NOT NULL COMMENT 'Stable app-generated device identifier (not biometric data)',
  `token_hash` varchar(255) NOT NULL COMMENT 'password_hash() of random device token; plaintext never stored',
  `device_label` varchar(120) DEFAULT NULL COMMENT 'Optional display label, e.g. citizen name or device name',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL COMMENT 'Updated on each successful device login',
  `expires_at` datetime NOT NULL COMMENT 'Token expiry (default 90 days from registration)',
  `revoked_at` datetime DEFAULT NULL COMMENT 'Set when citizen disables biometric or re-registers',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_id` (`device_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_citizen_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
