-- Idempotent offline sync: one server row per client local_uuid
ALTER TABLE `evac_registrations`
  ADD COLUMN `client_local_uuid` VARCHAR(36) NULL DEFAULT NULL AFTER `updated_at`;

ALTER TABLE `evac_registrations`
  ADD UNIQUE KEY `uq_evac_client_local_uuid` (`client_local_uuid`);
