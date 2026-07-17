PRAGMA foreign_keys = OFF;

CREATE TABLE sizestation_mailbox_assignments_new (
    id varchar(36) PRIMARY KEY,
    issuer varchar(255) NOT NULL,
    external_user_id varchar(255) NOT NULL,
    principal_id integer,
    mailbox_address varchar(254) NOT NULL,
    display_label varchar(255),
    credential_provider varchar(32) NOT NULL,
    credential_reference varchar(512) NOT NULL,
    is_anchor integer NOT NULL DEFAULT 0 CHECK(is_anchor IN (0, 1)),
    is_preferred integer NOT NULL DEFAULT 0 CHECK(is_preferred IN (0, 1)),
    enabled integer NOT NULL DEFAULT 1 CHECK(enabled IN (0, 1)),
    anchor_guard varchar(8),
    preferred_guard varchar(12),
    materialization_status varchar(16) NOT NULL DEFAULT 'pending'
        CHECK(materialization_status IN ('pending', 'anchor', 'materialized', 'disabled', 'failed', 'orphaned')),
    ident_switch_record_id integer,
    roundcube_identity_id integer,
    credential_status varchar(16) NOT NULL DEFAULT 'unknown'
        CHECK(credential_status IN ('unknown', 'valid', 'invalid', 'unavailable')),
    created_by varchar(255) NOT NULL,
    created_at varchar(32) NOT NULL,
    updated_at varchar(32) NOT NULL,
    bound_at varchar(32),
    last_validated_at varchar(32),
    last_used_at varchar(32),
    last_error_code varchar(64),
    FOREIGN KEY(principal_id) REFERENCES sizestation_oidc_principals(id),
    UNIQUE(issuer, external_user_id, mailbox_address),
    UNIQUE(principal_id, mailbox_address),
    UNIQUE(issuer, external_user_id, anchor_guard),
    UNIQUE(issuer, external_user_id, preferred_guard),
    CHECK(
        (is_anchor = 1 AND enabled = 1 AND COALESCE(anchor_guard, '') = 'anchor')
        OR ((is_anchor = 0 OR enabled = 0) AND anchor_guard IS NULL)
    ),
    CHECK(
        (is_preferred = 1 AND enabled = 1 AND COALESCE(preferred_guard, '') = 'preferred')
        OR ((is_preferred = 0 OR enabled = 0) AND preferred_guard IS NULL)
    )
);

INSERT INTO sizestation_mailbox_assignments_new
    SELECT * FROM sizestation_mailbox_assignments;

DROP TABLE sizestation_mailbox_assignments;
ALTER TABLE sizestation_mailbox_assignments_new RENAME TO sizestation_mailbox_assignments;

CREATE INDEX IX_sizestation_assignments_principal
    ON sizestation_mailbox_assignments(principal_id);
CREATE INDEX IX_sizestation_assignments_external
    ON sizestation_mailbox_assignments(issuer, external_user_id);
CREATE INDEX IX_sizestation_assignments_credential
    ON sizestation_mailbox_assignments(credential_provider, credential_reference);

ALTER TABLE ident_switch ADD COLUMN archive_mbox varchar(64);

UPDATE system SET value = '2026071701' WHERE name = 'sizestation_oidc-version';
UPDATE system SET value = '2026071701' WHERE name = 'ident_switch-version';

PRAGMA foreign_keys = ON;
