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

UPDATE `system` SET `value` = '2026071601' WHERE `name` = 'sizestation_oidc-version';
