ALTER TABLE `sizestation_mailbox_assignments`
    DROP INDEX `sizestation_assignment_credential_reference`,
    ADD INDEX `IX_sizestation_assignments_credential` (`credential_provider`, `credential_reference`);

ALTER TABLE `ident_switch` ADD `archive_mbox` varchar(64) DEFAULT NULL;

UPDATE `system` SET `value` = '2026071701' WHERE `name` = 'sizestation_oidc-version';
UPDATE `system` SET `value` = '2026071701' WHERE `name` = 'ident_switch-version';
