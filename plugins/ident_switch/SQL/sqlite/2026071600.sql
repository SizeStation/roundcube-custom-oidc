ALTER TABLE ident_switch
    ADD COLUMN credential_provider varchar(32) NOT NULL DEFAULT 'database';
ALTER TABLE ident_switch
    ADD COLUMN credential_reference varchar(512);
ALTER TABLE ident_switch
    ADD COLUMN managed_externally integer NOT NULL DEFAULT 0
        CHECK(managed_externally IN (0, 1));
ALTER TABLE ident_switch
    ADD COLUMN managed_assignment_id varchar(36);

CREATE UNIQUE INDEX ident_switch_managed_assignment
    ON ident_switch (managed_assignment_id);

UPDATE system SET value = '2026071600' WHERE name = 'ident_switch-version';
