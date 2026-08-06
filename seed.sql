-- Seed data for Florida Sound Quality (multi-role flows)
-- Safe to re-run: INSERT IGNORE on fixed IDs / emails / UUIDs
--
-- Default staff passwords (change in production):
--   admin@floridasoundquality.local  /  admin123
--   judge@floridasoundquality.local  /  judge123

INSERT IGNORE INTO users (id, email, password_hash, name, role) VALUES
(
    1,
    'admin@floridasoundquality.local',
    '$2y$12$NtRM.hCnm7yZR/XTiXgxtuvuuOxswWkI.bGqb8j0ruw8fFaaRDlTy',
    'Site Admin',
    'admin'
),
(
    2,
    'judge@floridasoundquality.local',
    '$2y$12$LozYe.mcaEU7OLYObIbytOLVq2VgrZ9FLKTgve3yx4o38L5gtxarS',
    'Alex Rivera',
    'judge'
);

INSERT IGNORE INTO events (id, name, event_date) VALUES
(1, 'Tampa Bay Showdown', '2026-03-15'),
(2, 'Orlando Spring Meet', '2026-04-02');

-- Sample competitors: registered (ready to score) and scored
INSERT IGNORE INTO competitors (
    id, status, name, email,
    vehicle_year, vehicle_make, vehicle_model, vehicle_color,
    created_by_user_id, registered_at, scorecard_sent_at
) VALUES
(
    2,
    'registered',
    'Casey Morgan', 'casey.morgan@example.com',
    2021, 'Subaru', 'BRZ', 'Blue',
    NULL, '2026-03-10 12:00:00', NULL
),
(
    3,
    'scored',
    'Jane Doe', 'jane.doe@example.com',
    2022, 'Honda', 'Civic', 'Black',
    NULL, '2026-03-10 12:05:00', NULL
),
(
    4,
    'scored',
    'Marcus Chen', 'marcus.chen@example.com',
    2019, 'Ford', 'Mustang', 'Red',
    NULL, '2026-03-10 12:10:00', NULL
),
(
    5,
    'scored',
    'Priya Patel', 'priya.patel@example.com',
    2021, 'Toyota', 'GR86', 'White',
    NULL, '2026-03-10 12:15:00', NULL
),
(
    6,
    'scored',
    'Taylor Brooks', 'taylor.brooks@example.com',
    2023, 'Tesla', 'Model 3', 'Gray',
    NULL, '2026-04-01 09:00:00', '2026-04-02 18:00:00'
),
(
    7,
    'scored',
    'Riley Gomez', 'riley.gomez@example.com',
    2020, 'Chevrolet', 'Camaro', 'Yellow',
    NULL, '2026-04-01 09:05:00', NULL
);

INSERT IGNORE INTO scores (
    submission_uuid, competitor_id, judge_user_id, event_id,
    event_date, event_name, judge_name,
    competitor_name, competitor_email,
    vehicle_year, vehicle_make, vehicle_model, vehicle_color,
    sub_bass, mid_bass, midrange, high_freq, spectral_balance, tonal_notes,
    listening_position, width, height, depth, ambience, stage_notes,
    imaging_score, imaging_notes,
    noise, listening_pleasure, noise_notes, listening_notes,
    tonal_total, stage_total, grand_total, placement
) VALUES
(
    '11111111-1111-4111-8111-111111111111', 3, 2, 1,
    '2026-03-15', 'Tampa Bay Showdown', 'Alex Rivera',
    'Jane Doe', 'jane.doe@example.com',
    2022, 'Honda', 'Civic', 'Black',
    18, 17, 16, 15, 17, 'Tight low end, clean mids.',
    14, 13, 12, 9, 8, 'Wide and deep stage.',
    42, 'Center image locked.',
    4, 9, NULL, 'Very listenable.',
    83, 56, 194, '1st'
),
(
    '22222222-2222-4222-8222-222222222222', 4, 2, 1,
    '2026-03-15', 'Tampa Bay Showdown', 'Alex Rivera',
    'Marcus Chen', 'marcus.chen@example.com',
    2019, 'Ford', 'Mustang', 'Red',
    16, 15, 14, 13, 14, NULL,
    12, 11, 11, 7, 7, NULL,
    36, NULL,
    3, 7, 'Some road noise.', NULL,
    72, 48, 166, '2nd'
),
(
    '33333333-3333-4333-8333-333333333333', 5, 2, 1,
    '2026-03-15', 'Tampa Bay Showdown', 'Alex Rivera',
    'Priya Patel', 'priya.patel@example.com',
    2021, 'Toyota', 'GR86', 'White',
    14, 14, 15, 16, 15, 'Bright but controlled highs.',
    11, 12, 10, 8, 6, NULL,
    38, NULL,
    5, 8, NULL, NULL,
    74, 47, 172, '3rd'
),
(
    '55555555-5555-4555-8555-555555555555', 6, 2, 2,
    '2026-04-02', 'Orlando Spring Meet', 'Alex Rivera',
    'Taylor Brooks', 'taylor.brooks@example.com',
    2023, 'Tesla', 'Model 3', 'Gray',
    17, 18, 17, 16, 18, 'Excellent balance.',
    13, 14, 13, 9, 9, 'Tall image.',
    45, 'Pinpoint imaging.',
    5, 9, NULL, NULL,
    86, 58, 203, '1st'
),
(
    '66666666-6666-4666-8666-666666666666', 7, 2, 2,
    '2026-04-02', 'Orlando Spring Meet', 'Alex Rivera',
    'Riley Gomez', 'riley.gomez@example.com',
    2020, 'Chevrolet', 'Camaro', 'Yellow',
    15, 14, 13, 14, 13, NULL,
    12, 12, 11, 8, 7, NULL,
    40, NULL,
    4, 8, NULL, NULL,
    69, 50, 171, '2nd'
);
