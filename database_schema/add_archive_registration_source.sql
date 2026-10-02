-- Preserve app vs walk-in source when archiving evacuee records.

ALTER TABLE `evac_registrations_archive`
  ADD COLUMN IF NOT EXISTS `source_user_id` INT UNSIGNED NULL
    COMMENT 'Citizen account when app-originated registration' AFTER `center_id`;
