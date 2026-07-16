CREATE TABLE sizestation_oidc_principals (
    id integer PRIMARY KEY AUTOINCREMENT,
    issuer varchar(255) NOT NULL,
    subject varchar(255) NOT NULL,
    external_user_id varchar(255) NOT NULL,
    oidc_email varchar(254),
    preferred_username varchar(255),
    display_name varchar(255),
    roundcube_user_id integer,
    status varchar(16) NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending', 'active', 'disabled', 'error')),
    created_at varchar(32) NOT NULL,
    updated_at varchar(32) NOT NULL,
    first_login_at varchar(32),
    last_login_at varchar(32),
    UNIQUE(issuer, subject),
    UNIQUE(issuer, external_user_id)
);

CREATE TABLE sizestation_mailbox_assignments (
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
    UNIQUE(credential_provider, credential_reference),
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

CREATE INDEX IX_sizestation_assignments_principal
    ON sizestation_mailbox_assignments(principal_id);
CREATE INDEX IX_sizestation_assignments_external
    ON sizestation_mailbox_assignments(issuer, external_user_id);

CREATE TABLE sizestation_oidc_audit_log (
    id integer PRIMARY KEY AUTOINCREMENT,
    principal_id integer,
    assignment_id varchar(36),
    actor_type varchar(32) NOT NULL,
    actor_identifier varchar(255) NOT NULL,
    event_type varchar(64) NOT NULL,
    source_ip varchar(45),
    user_agent varchar(512),
    metadata_json text NOT NULL,
    created_at varchar(32) NOT NULL,
    FOREIGN KEY(principal_id) REFERENCES sizestation_oidc_principals(id),
    FOREIGN KEY(assignment_id) REFERENCES sizestation_mailbox_assignments(id)
);

CREATE INDEX IX_sizestation_audit_principal ON sizestation_oidc_audit_log(principal_id);
CREATE INDEX IX_sizestation_audit_assignment ON sizestation_oidc_audit_log(assignment_id);
CREATE INDEX IX_sizestation_audit_created ON sizestation_oidc_audit_log(created_at);

CREATE TABLE sizestation_oidc_replay_codes (
    code_hash varchar(64) PRIMARY KEY,
    expires_at varchar(32) NOT NULL,
    created_at varchar(32) NOT NULL
);

CREATE TABLE sizestation_oidc_rate_limits (
    limiter_key varchar(96) PRIMARY KEY,
    window_started_at varchar(32) NOT NULL,
    attempts integer NOT NULL CHECK(attempts > 0),
    expires_at varchar(32) NOT NULL
);

INSERT INTO system (name, value) VALUES ('sizestation_oidc-version', '2026071602');
