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

UPDATE system SET value = '2026071601' WHERE name = 'sizestation_oidc-version';
