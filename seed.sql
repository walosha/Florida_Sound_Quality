-- Seed data for Florida Sound Quality
-- Safe to re-run: INSERT IGNORE on fixed emails / UUIDs
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

INSERT IGNORE INTO scores (
    submission_uuid, competitor_id, judge_user_id,
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
    '11111111-1111-4111-8111-111111111111', NULL, 2,
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
    '22222222-2222-4222-8222-222222222222', NULL, 2,
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
    '33333333-3333-4333-8333-333333333333', NULL, 2,
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
    '44444444-4444-4444-8444-444444444444', NULL, 2,
    '2026-03-15', 'Tampa Bay Showdown', 'Alex Rivera',
    'Chris Nguyen', 'chris.nguyen@example.com',
    2018, 'Subaru', 'WRX', 'Blue',
    12, 13, 12, 11, 12, NULL,
    10, 9, 9, 6, 5, NULL,
    30, NULL,
    3, 6, NULL, NULL,
    60, 39, 138, NULL
),
(
    '55555555-5555-4555-8555-555555555555', NULL, 2,
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
    '66666666-6666-4666-8666-666666666666', NULL, 2,
    '2026-04-02', 'Orlando Spring Meet', 'Alex Rivera',
    'Riley Gomez', 'riley.gomez@example.com',
    2020, 'Chevrolet', 'Camaro', 'Yellow',
    15, 14, 13, 14, 13, NULL,
    12, 12, 11, 8, 7, NULL,
    40, NULL,
    4, 8, NULL, NULL,
    69, 50, 171, '2nd'
),
(
    '77777777-7777-4777-8777-777777777777', NULL, 2,
    '2026-04-02', 'Orlando Spring Meet', 'Alex Rivera',
    'Morgan Lee', 'morgan.lee@example.com',
    2017, 'Mazda', 'MX-5', 'Red',
    13, 13, 12, 12, 13, NULL,
    10, 10, 9, 7, 6, NULL,
    32, NULL,
    4, 7, NULL, NULL,
    63, 42, 148, NULL
),
(
    '88888888-8888-4888-8888-888888888888', NULL, 2,
    '2026-04-02', 'Orlando Spring Meet', 'Alex Rivera',
    'Avery Kim', 'avery.kim@example.com',
    2015, 'Honda', 'Accord', 'Silver',
    11, 12, 11, 10, 11, NULL,
    9, 8, 8, 5, 5, NULL,
    28, NULL,
    3, 6, NULL, NULL,
    55, 35, 127, NULL
);
