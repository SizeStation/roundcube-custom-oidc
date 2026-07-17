ALTER TABLE sizestation_mailbox_assignments
    DROP CONSTRAINT IF EXISTS sizestation_mailbox_assignmen_credential_provider_credentia_key;
DROP INDEX IF EXISTS sizestation_assignment_credential_reference;
CREATE INDEX IX_sizestation_assignments_credential
    ON sizestation_mailbox_assignments(credential_provider, credential_reference);

ALTER TABLE ident_switch ADD COLUMN archive_mbox varchar(64);

UPDATE system SET value = '2026071701' WHERE name = 'sizestation_oidc-version';
UPDATE system SET value = '2026071701' WHERE name = 'ident_switch-version';
