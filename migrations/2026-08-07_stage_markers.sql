-- Optional sound-stage diagram marker pins (viewBox-relative JSON arrays).
-- Visual only — never used to derive Width/Height/Depth/Ambience scores.
-- Safe to re-run on MySQL 8+ if columns already exist (will error once; ignore).

ALTER TABLE scores
    ADD COLUMN stage_markers_wh JSON NULL AFTER stage_notes,
    ADD COLUMN stage_markers_depth JSON NULL AFTER stage_markers_wh;
