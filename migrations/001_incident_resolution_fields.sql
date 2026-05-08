-- Optional: run on MySQL/MariaDB after backup if columns are missing.
-- If MySQL errors on duplicate column, drop the lines for columns you already have.

ALTER TABLE incidents
  ADD COLUMN resolution_photo TEXT NULL,
  ADD COLUMN resolution_notes TEXT NULL,
  ADD COLUMN resolved_by VARCHAR(64) NULL,
  ADD COLUMN resolved_by_role ENUM('barangay','agency') NULL,
  ADD COLUMN resolved_at DATETIME NULL;
