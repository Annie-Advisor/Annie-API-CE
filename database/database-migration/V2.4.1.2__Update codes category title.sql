UPDATE codes AS new_codes
SET
  value = jsonb_build_object('title', coalesce(codes.value->>ui.lang,codes.value->>codelang.keys[1]))
, updated = now(), updatedby = 'Annie'
FROM codes
LEFT JOIN LATERAL (select array(select * from jsonb_object_keys(codes.value)) as keys) codelang ON 1=1
LEFT JOIN (select config.value#>>'{}' as lang from config where config.segment = 'ui' and config.field = 'language') ui ON 1=1
WHERE codes.codeset = 'category'
AND coalesce(codes.value->>ui.lang,codes.value->>codelang.keys[1]) IS NOT NULL
-- link update table and from table
AND new_codes.id = codes.id
;

-- also mark old ones deprecated
UPDATE codes
SET validuntil = now()
, updated = now(), updatedby = 'Annie'
WHERE codeset = 'category'
AND code IN ('W','X','Y','Z')
AND validuntil IS NULL
;
