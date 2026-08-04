-- Optional reference image: original paper scoring sheet stored in S3.
-- Safe to re-run on MySQL 8+; on 5.7 run once only.

ALTER TABLE scores
    ADD COLUMN paper_sheet_key VARCHAR(512) NULL AFTER placement;
