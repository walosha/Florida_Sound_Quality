-- Florida Sound Quality — scoring app schema
-- MySQL 5.7+ / Railway MySQL
-- Import once: mysql < schema.sql   or   railway connect MySQL < schema.sql

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255)     NOT NULL,
    password_hash   VARCHAR(255)     NOT NULL,
    name            VARCHAR(255)     NOT NULL,
    role            ENUM('admin', 'judge') NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competitors (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- 'invited' retained for legacy rows; new registrations use 'registered'
    status             ENUM('invited', 'registered', 'scored') NOT NULL DEFAULT 'registered',
    name               VARCHAR(255)     NULL,
    email              VARCHAR(255)     NULL,
    vehicle_year       SMALLINT UNSIGNED NULL,
    vehicle_make       VARCHAR(100)     NULL,
    vehicle_model      VARCHAR(100)     NULL,
    vehicle_color      VARCHAR(50)      NULL,
    created_by_user_id INT UNSIGNED     NULL,
    registered_at      TIMESTAMP        NULL,
    scorecard_sent_at  TIMESTAMP        NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_competitors_status (status),
    INDEX idx_competitors_email (email),
    CONSTRAINT fk_competitors_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    event_date  DATE         NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_date (event_date DESC),
    UNIQUE KEY uq_events_name_date (name, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scores (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_uuid  CHAR(36)         NOT NULL,
    -- linked entities; denormalized competitor/event fields kept for PDF snapshots
    competitor_id    INT UNSIGNED     NULL,
    judge_user_id    INT UNSIGNED     NULL,
    event_id         INT UNSIGNED     NULL,
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
    -- optional sound-stage diagram pins (viewBox coords; visual only, not scores)
    stage_markers_wh    JSON NULL,
    stage_markers_depth JSON NULL,
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
    -- optional photo/scan of the original paper scoring sheet (S3 object key)
    paper_sheet_key  VARCHAR(512)     NULL,
    -- meta
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_submission (submission_uuid),
    -- one score per competitor (NULLs allowed for legacy rows)
    UNIQUE KEY uq_scores_competitor (competitor_id),
    INDEX idx_event (event_name),
    INDEX idx_total (grand_total DESC),
    INDEX idx_scores_judge (judge_user_id),
    INDEX idx_scores_event_id (event_id),
    CONSTRAINT fk_scores_competitor
        FOREIGN KEY (competitor_id) REFERENCES competitors(id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_scores_judge
        FOREIGN KEY (judge_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_scores_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE SET NULL
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
