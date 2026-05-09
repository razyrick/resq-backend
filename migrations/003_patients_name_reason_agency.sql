-- Add patient identity, clinical/referral reason, and routing agency.
-- Run after 002_patients_table.sql (or on any existing patients table).
-- agency_id is optional application-level routing (same pattern as incidents.agency_id: no FK in migrations).

ALTER TABLE patients
  ADD COLUMN full_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  ADD COLUMN reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  ADD COLUMN agency_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
