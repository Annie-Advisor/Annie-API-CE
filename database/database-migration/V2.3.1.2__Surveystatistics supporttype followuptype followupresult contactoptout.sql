ALTER TABLE surveystatistics
  ADD COLUMN IF NOT EXISTS supporttype varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS followuptype varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS followupresult varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS contactoptout date NULL;
