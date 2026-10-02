-- Individual arrival records per evac_registration (actual presence at center).
-- Household profile (family_members) remains unchanged when someone does not arrive.

CREATE TABLE IF NOT EXISTS `evac_registration_members` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_id`         INT UNSIGNED NOT NULL,
  `center_id`               INT UNSIGNED NOT NULL,
  `is_household_head`       TINYINT(1) NOT NULL DEFAULT 0,
  `source_family_member_id` INT UNSIGNED NULL COMMENT 'Link to family_members when app-registered household',
  `source_user_id`          INT UNSIGNED NULL COMMENT 'Citizen account when is_household_head',
  `full_name`               VARCHAR(200) NOT NULL,
  `sex`                     ENUM('male','female','prefer_not_to_say') DEFAULT NULL,
  `birthday`                DATE DEFAULT NULL,
  `primary_category`        ENUM('adults','children','seniors','infants_toddlers') NOT NULL,
  `is_pwd`                  TINYINT(1) NOT NULL DEFAULT 0,
  `is_pregnant`             TINYINT(1) NOT NULL DEFAULT 0,
  `is_lactating`            TINYINT(1) NOT NULL DEFAULT 0,
  `is_present`              TINYINT(1) NOT NULL DEFAULT 1,
  `arrived_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `checked_out_at`          DATETIME NULL,
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_erm_registration` (`registration_id`),
  KEY `idx_erm_center` (`center_id`),
  KEY `idx_erm_source_member` (`source_family_member_id`),
  CONSTRAINT `fk_erm_registration`
    FOREIGN KEY (`registration_id`) REFERENCES `evac_registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `evac_registrations`
  ADD COLUMN IF NOT EXISTS `source_user_id` INT UNSIGNED NULL
    COMMENT 'Citizen account for app-originated registration' AFTER `center_id`,
  ADD COLUMN IF NOT EXISTS `registration_mode` ENUM('aggregate','members') NOT NULL DEFAULT 'aggregate'
    COMMENT 'aggregate=legacy count-only; members=individual arrival rows' AFTER `source_user_id`,
  ADD COLUMN IF NOT EXISTS `expected_total_members` INT UNSIGNED NULL AFTER `total_members`,
  ADD COLUMN IF NOT EXISTS `expected_adults` INT UNSIGNED NULL AFTER `expected_total_members`,
  ADD COLUMN IF NOT EXISTS `expected_children` INT UNSIGNED NULL AFTER `expected_adults`,
  ADD COLUMN IF NOT EXISTS `expected_seniors` INT UNSIGNED NULL AFTER `expected_children`,
  ADD COLUMN IF NOT EXISTS `expected_pwds` INT UNSIGNED NULL AFTER `expected_seniors`,
  ADD COLUMN IF NOT EXISTS `expected_pregnant_women` INT UNSIGNED NULL AFTER `expected_pwds`,
  ADD COLUMN IF NOT EXISTS `expected_lactating_mothers` INT UNSIGNED NULL AFTER `expected_pregnant_women`,
  ADD COLUMN IF NOT EXISTS `expected_infants_toddlers` INT UNSIGNED NULL AFTER `expected_lactating_mothers`;

ALTER TABLE `evac_navigation_tracking`
  MODIFY COLUMN `status` ENUM('navigating','partial_arrival','arrived','cancelled') NOT NULL DEFAULT 'navigating';
