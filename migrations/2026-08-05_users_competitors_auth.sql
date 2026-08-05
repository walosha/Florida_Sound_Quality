-- Phase 0: users, competitors, scores FKs for role-based auth.
-- Safe to re-run checks are limited — run once on existing DBs.
-- Fresh installs should use schema.sql instead.

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
    invite_token       CHAR(64)         NOT NULL,
    status             ENUM('invited', 'registered', 'scored') NOT NULL DEFAULT 'invited',
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

    UNIQUE KEY uq_invite_token (invite_token),
    INDEX idx_competitors_status (status),
    INDEX idx_competitors_email (email),
    CONSTRAINT fk_competitors_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add FK columns to scores (MySQL 5.7: run once; duplicate column errors if re-run)
ALTER TABLE scores
    ADD COLUMN competitor_id INT UNSIGNED NULL AFTER submission_uuid,
    ADD COLUMN judge_user_id INT UNSIGNED NULL AFTER competitor_id;

ALTER TABLE scores
    ADD UNIQUE KEY uq_scores_competitor (competitor_id),
    ADD INDEX idx_scores_judge (judge_user_id);

ALTER TABLE scores
    ADD CONSTRAINT fk_scores_competitor
        FOREIGN KEY (competitor_id) REFERENCES competitors(id)
        ON DELETE RESTRICT,
    ADD CONSTRAINT fk_scores_judge
        FOREIGN KEY (judge_user_id) REFERENCES users(id)
        ON DELETE SET NULL;
