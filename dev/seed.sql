-- Fictional data for local testing only. Any resemblance to real schools or people is coincidental.
-- Columns the live database has but the plugin's CREATE TABLE does not define:
ALTER TABLE wp_scholars_bowl_registrations
  ADD COLUMN IF NOT EXISTS grade_level varchar(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS dietary_restrictions text,
  ADD COLUMN IF NOT EXISTS additional_students int(2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS additional_adults int(2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS is_test_mode tinyint(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS discount_amount decimal(10,2) DEFAULT '0.00',
  ADD COLUMN IF NOT EXISTS discount_reason text,
  ADD COLUMN IF NOT EXISTS status_notes text;
ALTER TABLE wp_scholars_bowl_events
  ADD COLUMN IF NOT EXISTS registration_deadline date DEFAULT NULL;

DELETE FROM wp_scholars_bowl_registrations;
DELETE FROM wp_scholars_bowl_events;

INSERT INTO wp_scholars_bowl_events (id, event_name, event_slug, event_date, event_location, early_bird_deadline, early_bird_rate, standard_rate, deposit_amount, morning_classifications, afternoon_classifications, morning_session_time, afternoon_session_time, stripe_mode, stripe_test_secret_key, event_status, created_date) VALUES
 (1, 'High School Scholars Bowl 2025 (dev)', 'hs-2025-dev', '2025-10-29', 'Wadley Campus', '2025-10-01', 14.00, 15.00, 25.00, 'Private,1A,2A,3A', '4A,5A,6A,7A', '8:30 AM', '12:15 PM', 'test', 'sk_test_DEV_SECRET_MUST_NEVER_APPEAR_IN_API', 'completed', '2025-08-01 00:00:00'),
 (2, 'High School Scholars Bowl 2026 (dev)', 'hs-2026-dev', '2026-10-28', 'Wadley Campus', '2026-10-01', 14.00, 15.00, 25.00, 'Private,1A,2A,3A', '4A,5A,6A,7A', '8:30 AM', '12:15 PM', 'test', 'sk_test_DEV_SECRET_MUST_NEVER_APPEAR_IN_API', 'active', '2026-08-01 00:00:00');

INSERT INTO wp_scholars_bowl_registrations (id, event_id, school_name, contact_name, contact_email, contact_phone, competing_students, additional_attendees, additional_students, additional_adults, coaches, school_classification, grade_level, session_time, payment_option, payment_method, total_amount, amount_paid, balance_due, payment_status, registration_status, payment_notes, dietary_restrictions, is_test_mode, registration_date, last_updated) VALUES
 (12, 1, 'Pinecrest Academy', 'Avery Testperson', 'avery@pinecrest.example', '5550100101', 6, 0, 0, 0, 1, '', 'Private', 'morning', 'full', 'credit', 90, 90, 0, 'completed', 'confirmed', '', 'None', 0, '2025-10-09 13:38:50', '2025-10-09 13:38:50'),
 (18, 1, 'Live Test 4', 'Test Admin', 'admin@example.test', '5550100000', 3, 0, 0, 0, 0, '', 'Private', 'morning', 'deposit', 'credit', 45, 25, 20, 'pending', 'pending', '', 'None', 1, '2025-10-09 17:45:05', '2025-10-09 17:45:05'),
 (36, 2, 'Maple Grove High School', 'Sam Notreal', 'sam@maplegrove.example', '5550100107', 8, 0, 0, 0, 2, '', '5A', 'afternoon', 'full', 'check', 112, 0, 112, 'pending', 'pending', '', 'None', 0, '2026-08-27 14:56:36', '2026-08-27 14:56:36'),
 (40, 2, 'Cedar Hollow School', 'Morgan Example', 'morgan@cedarhollow.example', '555-010-0103', 8, 3, 3, 0, 2, '', '2A', 'morning', 'full', 'credit', 53, 53, 0, 'completed', 'confirmed', '', 'One vegetarian student', 0, '2026-09-22 13:01:38', '2026-09-22 13:01:38'),
 (44, 2, 'Riverbend Christian A', 'Jordan Sample', 'jsample@riverbend.example', '5550100102', 7, 0, 0, 0, 2, '', '1A', 'morning', 'deposit', 'credit', 98, 25, 73, 'partial', 'confirmed', '', 'None', 0, '2026-09-29 17:38:59', '2026-09-29 17:38:59'),
 (45, 2, 'Riverbend Christian B', 'Jordan Sample', 'jsample@riverbend.example', '5550100102', 7, 4, 0, 4, 0, '', '1A', 'morning', 'deposit', 'credit', 154, 25, 129, 'partial', 'pending', '', 'None', 0, '2026-09-29 17:41:31', '2026-09-29 17:41:31'),
 (46, 2, 'Summit High School', 'Riley Fictional', 'riley@summit.example', '5550100105', 6, 1, 0, 1, 1, '', '6A', 'afternoon', 'full', 'credit', 98, 98, 0, 'completed', 'confirmed', '', 'None', 0, '2026-10-01 18:04:30', '2026-10-01 18:04:30'),
 (47, 2, 'Lakeview High School', 'Casey Placeholder', 'casey@lakeview.example', '555-010-0104', 6, 0, 0, 0, 1, '4A', '', 'afternoon', 'full', 'check', 84, 0, 84, 'pending', 'cancelled', 'Withdrew', 'None', 0, '2026-09-18 15:51:29', '2026-10-05 09:00:00'),
 (49, 2, 'Oak Ridge Academy', 'Taylor Madeup', 'taylor@oakridge.example', '5550100106', 6, 0, 0, 0, 1, '', '', 'morning', 'full', 'credit', 90, 90, 0, 'completed', 'confirmed', '', 'None', 0, '2026-10-10 10:00:00', '2026-10-10 10:00:00');
