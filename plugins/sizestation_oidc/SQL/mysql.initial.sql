CREATE TABLE IF NOT EXISTS `sizestation_oidc_principals` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `issuer` varchar(255) NOT NULL,
    `subject` varchar(255) NOT NULL,
    `external_user_id` varchar(255) NOT NULL,
    `oidc_email` varchar(254),
    `preferred_username` varchar(255),
    `display_name` varchar(255),
    `roundcube_user_id` int UNSIGNED,
    `status` varchar(16) NOT NULL DEFAULT 'pending',
    `created_at` varchar(32) NOT NULL,
    `updated_at` varchar(32) NOT NULL,
    `first_login_at` varchar(32),
    `last_login_at` varchar(32),
    PRIMARY KEY (`id`),
    UNIQUE KEY `sizestation_principal_subject` (`issuer`, `subject`),
    UNIQUE KEY `sizestation_principal_external` (`issuer`, `external_user_id`),
    CHECK (`status` IN ('pending', 'active', 'disabled', 'error'))
);

CREATE TABLE IF NOT EXISTS `sizestation_mailbox_assignments` (
    `id` varchar(36) NOT NULL,
    `issuer` varchar(255) NOT NULL,
    `external_user_id` varchar(255) NOT NULL,
    `principal_id` bigint UNSIGNED,
    `mailbox_address` varchar(254) NOT NULL,
    `display_label` varchar(255),
    `credential_provider` varchar(32) NOT NULL,
    `credential_reference` varchar(512) NOT NULL,
    `is_anchor` tinyint NOT NULL DEFAULT 0,
    `is_preferred` tinyint NOT NULL DEFAULT 0,
    `enabled` tinyint NOT NULL DEFAULT 1,
    `anchor_guard` varchar(8),
    `preferred_guard` varchar(12),
    `materialization_status` varchar(16) NOT NULL DEFAULT 'pending',
    `ident_switch_record_id` int UNSIGNED,
    `roundcube_identity_id` int UNSIGNED,
    `credential_status` varchar(16) NOT NULL DEFAULT 'unknown',
    `created_by` varchar(255) NOT NULL,
    `created_at` varchar(32) NOT NULL,
    `updated_at` varchar(32) NOT NULL,
    `bound_at` varchar(32),
    `last_validated_at` varchar(32),
    `last_used_at` varchar(32),
    `last_error_code` varchar(64),
    PRIMARY KEY (`id`),
    UNIQUE KEY `sizestation_assignment_external_mailbox` (`issuer`, `external_user_id`, `mailbox_address`),
    UNIQUE KEY `sizestation_assignment_credential_reference` (`credential_provider`, `credential_reference`),
    UNIQUE KEY `sizestation_assignment_principal_mailbox` (`principal_id`, `mailbox_address`),
    UNIQUE KEY `sizestation_assignment_anchor` (`issuer`, `external_user_id`, `anchor_guard`),
    UNIQUE KEY `sizestation_assignment_preferred` (`issuer`, `external_user_id`, `preferred_guard`),
    INDEX `IX_sizestation_assignments_principal` (`principal_id`),
    CONSTRAINT `fk_sizestation_assignment_principal` FOREIGN KEY (`principal_id`)
        REFERENCES `sizestation_oidc_principals` (`id`),
    CHECK (`is_anchor` IN (0, 1)),
    CHECK (`is_preferred` IN (0, 1)),
    CHECK (`enabled` IN (0, 1)),
    CHECK (`materialization_status` IN ('pending', 'anchor', 'materialized', 'disabled', 'failed', 'orphaned')),
    CHECK (`credential_status` IN ('unknown', 'valid', 'invalid', 'unavailable')),
    CHECK ((`is_anchor` = 1 AND `enabled` = 1 AND COALESCE(`anchor_guard`, '') = 'anchor')
        OR ((`is_anchor` = 0 OR `enabled` = 0) AND `anchor_guard` IS NULL)),
    CHECK ((`is_preferred` = 1 AND `enabled` = 1 AND COALESCE(`preferred_guard`, '') = 'preferred')
        OR ((`is_preferred` = 0 OR `enabled` = 0) AND `preferred_guard` IS NULL))
);

CREATE TABLE IF NOT EXISTS `sizestation_oidc_audit_log` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `principal_id` bigint UNSIGNED,
    `assignment_id` varchar(36),
    `actor_type` varchar(32) NOT NULL,
    `actor_identifier` varchar(255) NOT NULL,
    `event_type` varchar(64) NOT NULL,
    `source_ip` varchar(45),
    `user_agent` varchar(512),
    `metadata_json` text NOT NULL,
    `created_at` varchar(32) NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `IX_sizestation_audit_principal` (`principal_id`),
    INDEX `IX_sizestation_audit_assignment` (`assignment_id`),
    INDEX `IX_sizestation_audit_created` (`created_at`),
    CONSTRAINT `fk_sizestation_audit_principal` FOREIGN KEY (`principal_id`)
        REFERENCES `sizestation_oidc_principals` (`id`),
    CONSTRAINT `fk_sizestation_audit_assignment` FOREIGN KEY (`assignment_id`)
        REFERENCES `sizestation_mailbox_assignments` (`id`)
);

CREATE TABLE `sizestation_oidc_replay_codes` (
    `code_hash` varchar(64) NOT NULL,
    `expires_at` varchar(32) NOT NULL,
    `created_at` varchar(32) NOT NULL,
    PRIMARY KEY (`code_hash`)
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `sizestation_oidc_rate_limits` (
    `limiter_key` varchar(96) NOT NULL,
    `window_started_at` varchar(32) NOT NULL,
    `attempts` integer NOT NULL,
    `expires_at` varchar(32) NOT NULL,
    PRIMARY KEY (`limiter_key`),
    CHECK (`attempts` > 0)
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `system` (`name`, `value`) VALUES ('sizestation_oidc-version', '2026071602');
