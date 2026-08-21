ALTER TABLE `family_profiles`
  ADD COLUMN IF NOT EXISTS `pregnant_women` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `pwds`,
  ADD COLUMN IF NOT EXISTS `lactating_mothers` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `pregnant_women`,
  ADD COLUMN IF NOT EXISTS `infants_toddlers` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `lactating_mothers`;

ALTER TABLE `evac_registrations`
  ADD COLUMN IF NOT EXISTS `pregnant_women` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `pwds`,
  ADD COLUMN IF NOT EXISTS `lactating_mothers` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `pregnant_women`,
  ADD COLUMN IF NOT EXISTS `infants_toddlers` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `lactating_mothers`;

ALTER TABLE `evac_registrations_archive`
  ADD COLUMN IF NOT EXISTS `pregnant_women` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `pwds`,
  ADD COLUMN IF NOT EXISTS `lactating_mothers` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `pregnant_women`,
  ADD COLUMN IF NOT EXISTS `infants_toddlers` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `lactating_mothers`;

ALTER TABLE `citizen_household`
  ADD COLUMN IF NOT EXISTS `pregnant_women` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `pwds`,
  ADD COLUMN IF NOT EXISTS `lactating_mothers` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `pregnant_women`,
  ADD COLUMN IF NOT EXISTS `infants_toddlers` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 AFTER `lactating_mothers`;
