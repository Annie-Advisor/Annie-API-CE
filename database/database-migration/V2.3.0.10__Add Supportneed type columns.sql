ALTER TABLE supportneed
  ADD COLUMN IF NOT EXISTS supporttype varchar(100) NULL,
  ADD COLUMN IF NOT EXISTS followuptype varchar(100) NULL;
