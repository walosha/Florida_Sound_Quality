-- Florida Sound Quality — scoring app schema
-- MySQL 5.7+ / Railway MySQL
-- Import once: mysql < schema.sql   or   railway connect MySQL < schema.sql

CREATE TABLE IF NOT EXISTS scores (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uuid  CHAR(36)         NOT NULL,
    -- header
    event_date       DATE             NOT NULL,
    event_name       VARCHAR(255)     NOT NULL,
    judge_name       VARCHAR(255)     NOT NULL,
    competitor_name  VARCHAR(255)     NOT NULL,
    competitor_email VARCHAR(255)     NOT NULL,
    -- vehicle
    vehicle_year     SMALLINT UNSIGNED,
    vehicle_make     VARCHAR(100),
    vehicle_model    VARCHAR(100),
    vehicle_color    VARCHAR(50),
    -- tonal accuracy (each 1–20)
    sub_bass         TINYINT UNSIGNED NOT NULL,
    mid_bass         TINYINT UNSIGNED NOT NULL,
    midrange         TINYINT UNSIGNED NOT NULL,
    high_freq        TINYINT UNSIGNED NOT NULL,
    spectral_balance TINYINT UNSIGNED NOT NULL,
    tonal_notes      TEXT,
    -- sound stage
    listening_position TINYINT UNSIGNED NOT NULL,
    width            TINYINT UNSIGNED NOT NULL,
    height           TINYINT UNSIGNED NOT NULL,
    depth            TINYINT UNSIGNED NOT NULL,
    ambience         TINYINT UNSIGNED NOT NULL,
    stage_notes      TEXT,
    -- imaging
    imaging_score    TINYINT UNSIGNED NOT NULL,
    imaging_notes    TEXT,
    -- noise & listening
    noise            TINYINT UNSIGNED NOT NULL,
    listening_pleasure TINYINT UNSIGNED NOT NULL,
    noise_notes      TEXT,
    listening_notes  TEXT,
    -- calculated (server-side)
    tonal_total      TINYINT UNSIGNED NOT NULL,
    stage_total      TINYINT UNSIGNED NOT NULL,
    grand_total      SMALLINT UNSIGNED NOT NULL,
    -- placement
    placement        VARCHAR(100),
    -- meta
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_submission (submission_uuid),
    INDEX idx_event (event_name),
    INDEX idx_total (grand_total DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limit (
    ip_address   VARCHAR(45) PRIMARY KEY,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DB-backed sessions so they survive Railway container redeploys
CREATE TABLE IF NOT EXISTS sessions (
    id          VARCHAR(128) PRIMARY KEY,
    data        MEDIUMBLOB   NOT NULL,
    last_access INT UNSIGNED NOT NULL,
    INDEX idx_last_access (last_access)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
