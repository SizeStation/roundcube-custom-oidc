ALTER TABLE `ident_switch` ADD `archive_mbox` varchar(64) DEFAULT NULL;

UPDATE `system` SET `value` = '2026071701' WHERE `name` = 'ident_switch-version';
