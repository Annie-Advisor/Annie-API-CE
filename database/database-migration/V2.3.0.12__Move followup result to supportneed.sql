-- "Move" followup.result to supportneed.followupresult
ALTER TABLE supportneed
  ADD COLUMN IF NOT EXISTS followupresult varchar(100) NULL;
ALTER TABLE followup
  DROP COLUMN IF EXISTS result;
