-- Extend patients.status for agency intake workflow (run after backup).
-- Flow: dispatch creates patient as `incoming` → agency confirms `arrived` → agency closes `resolved`.
-- Legacy rows may remain `ongoing` (direct resolve still allowed from API for those rows only).

ALTER TABLE patients
  MODIFY COLUMN status ENUM('ongoing', 'incoming', 'arrived', 'resolved')
  NOT NULL DEFAULT 'ongoing';

-- Optional one-time cleanup: mark dispatched-linked patients that are still `ongoing` as `incoming`
-- (uncomment if you want existing dispatched intakes to show the new workflow)
-- UPDATE patients p
-- INNER JOIN incidents i ON i.patient_id = p.patient_id
-- SET p.status = 'incoming', p.updated_at = NOW()
-- WHERE p.status = 'ongoing';
