-- Archive individual arrival records alongside evac_registrations_archive.

CREATE TABLE IF NOT EXISTS `evac_registration_members_archive` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `archive_registration_id` INT UNSIGNED NOT NULL COMMENT 'FK evac_registrations_archive.id',
  `original_member_id`      INT UNSIGNED NULL,
  `is_household_head`       TINYINT(1) NOT NULL DEFAULT 0,
  `full_name`               VARCHAR(200) NOT NULL,
  `sex`                     ENUM('male','female','prefer_not_to_say') DEFAULT NULL,
  `birthday`                DATE DEFAULT NULL,
  `primary_category`        ENUM('adults','children','seniors','infants_toddlers') NOT NULL,
  `is_pwd`                  TINYINT(1) NOT NULL DEFAULT 0,
  `is_pregnant`             TINYINT(1) NOT NULL DEFAULT 0,
  `is_lactating`            TINYINT(1) NOT NULL DEFAULT 0,
  `arrived_at`              DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erma_archive_reg` (`archive_registration_id`),
  CONSTRAINT `fk_erma_archive_reg`
    FOREIGN KEY (`archive_registration_id`) REFERENCES `evac_registrations_archive` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
