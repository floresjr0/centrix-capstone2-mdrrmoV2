-- Individual household members + family profile completion tracking.
-- family_profiles count columns remain as derived aggregate cache.

CREATE TABLE IF NOT EXISTS `family_members` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED NOT NULL,
  `full_name`        VARCHAR(200) NOT NULL,
  `sex`              ENUM('male','female','prefer_not_to_say') DEFAULT NULL,
  `birthday`         DATE DEFAULT NULL,
  `primary_category` ENUM('adults','children','seniors','infants_toddlers') NOT NULL,
  `is_pwd`           TINYINT(1) NOT NULL DEFAULT 0,
  `is_pregnant`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_lactating`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_family_members_user` (`user_id`),
  CONSTRAINT `fk_family_members_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `family_profiles`
  ADD COLUMN IF NOT EXISTS `lives_alone_confirmed` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Head explicitly confirmed solo household' AFTER `total_members`,
  ADD COLUMN IF NOT EXISTS `household_setup_at` DATETIME NULL
    COMMENT 'When family profile was first marked complete' AFTER `lives_alone_confirmed`,
  ADD COLUMN IF NOT EXISTS `profile_source` ENUM('legacy_counts','members') NOT NULL DEFAULT 'legacy_counts'
    COMMENT 'legacy_counts = pre-migration; members = individual-based' AFTER `household_setup_at`;

-- Mark existing rows as legacy count-based data.
UPDATE `family_profiles` SET `profile_source` = 'legacy_counts' WHERE `profile_source` IS NULL OR `profile_source` = 'legacy_counts';
