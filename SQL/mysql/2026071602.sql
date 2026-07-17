CREATE UNIQUE INDEX `sizestation_assignment_credential_reference`
    ON `sizestation_mailbox_assignments` (`credential_provider`, `credential_reference`);

UPDATE `system` SET `value` = '2026071602' WHERE `name` = 'sizestation_oidc-version';
