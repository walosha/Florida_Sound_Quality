-- Phase 4 cutover: refresh denormalized score header fields from competitors.
-- Safe to re-run. Leaves legacy scores (competitor_id IS NULL) unchanged.

UPDATE scores s
INNER JOIN competitors c ON c.id = s.competitor_id
SET
    s.competitor_name  = COALESCE(NULLIF(TRIM(c.name), ''), s.competitor_name),
    s.competitor_email = COALESCE(NULLIF(TRIM(c.email), ''), s.competitor_email),
    s.vehicle_year     = COALESCE(c.vehicle_year, s.vehicle_year),
    s.vehicle_make     = COALESCE(NULLIF(TRIM(c.vehicle_make), ''), s.vehicle_make),
    s.vehicle_model    = COALESCE(NULLIF(TRIM(c.vehicle_model), ''), s.vehicle_model),
    s.vehicle_color    = COALESCE(NULLIF(TRIM(c.vehicle_color), ''), s.vehicle_color)
WHERE c.name IS NOT NULL;
