UPDATE survey
SET status = 'IN PROGRESS'
, updated=now(), updatedby='Annie'
WHERE id = 'Y'
AND coalesce(status,'(no status)') != 'IN PROGRESS'
