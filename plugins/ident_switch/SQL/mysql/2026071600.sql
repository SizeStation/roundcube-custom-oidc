ALTER TABLE `ident_switch`
    ADD `credential_provider` varchar(32) NOT NULL DEFAULT 'database',
    ADD `credential_reference` varchar(512) DEFAULT NULL,
    ADD `managed_externally` tinyint NOT NULL DEFAULT 0,
    ADD `managed_assignment_id` varchar(36) DEFAULT NULL,
    ADD CONSTRAINT `ident_switch_managed_externally_check`
        CHECK (`managed_externally` IN (0, 1)),
    ADD UNIQUE INDEX `ident_switch_managed_assignment` (`managed_assignment_id`);

UPDATE `system` SET `value` = '2026071600' WHERE `name` = 'ident_switch-version';
