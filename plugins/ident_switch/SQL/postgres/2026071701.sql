ALTER TABLE ident_switch ADD COLUMN archive_mbox varchar(64);

UPDATE system SET value = '2026071701' WHERE name = 'ident_switch-version';
