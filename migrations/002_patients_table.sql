-- Optional: run on MySQL/MariaDB after backup if objects are missing.
-- If MySQL errors on duplicate table/column/constraint, skip or adjust the failing statement.
--
-- If ALTER TABLE incidents fails with errno 150 "Foreign key constraint is incorrectly formed":
--   1) Foreign keys need InnoDB. Check: SHOW TABLE STATUS WHERE Name = 'incidents'; (Engine).
--      If Engine is MyISAM, run first (after backup):
--        ALTER TABLE incidents ENGINE=InnoDB;
--   2) Charset/collation on both sides of the FK must match exactly. This file uses the
--      same CHARACTER SET + COLLATE on patients.patient_id and incidents.patient_id.
--      If it still fails, run: SHOW FULL COLUMNS FROM incidents WHERE Field = 'incident_id';
--      and set both patient_id columns to match that column's Collation.
--   3) If incidents uses MyISAM, uncomment and run first (FK require InnoDB; large tables = backup first):
--        ALTER TABLE incidents ENGINE=InnoDB;

CREATE TABLE patients (
  patient_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  status ENUM('ongoing', 'resolved') NOT NULL DEFAULT 'ongoing',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE incidents
  ADD COLUMN patient_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  ADD CONSTRAINT fk_incidents_patient_id
    FOREIGN KEY (patient_id) REFERENCES patients (patient_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
