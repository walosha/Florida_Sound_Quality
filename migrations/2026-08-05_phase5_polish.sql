-- Phase 5 polish: invite expiry/revoke, events table, scores.event_id
-- Run once on existing DBs. Fresh installs use schema.sql.

ALTER TABLE competitors
    ADD COLUMN expires_at TIMESTAMP NULL AFTER scorecard_sent_at,
    ADD COLUMN revoked_at TIMESTAMP NULL AFTER expires_at;

CREATE TABLE IF NOT EXISTS events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    event_date  DATE         NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_events_date (event_date DESC),
    UNIQUE KEY uq_events_name_date (name, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE scores
    ADD COLUMN event_id INT UNSIGNED NULL AFTER judge_user_id;

ALTER TABLE scores
    ADD INDEX idx_scores_event_id (event_id),
    ADD CONSTRAINT fk_scores_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE SET NULL;
