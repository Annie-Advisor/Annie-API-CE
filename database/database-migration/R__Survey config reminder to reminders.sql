update survey
set updated=now(), updatedby='Annie'
, config = config - 'reminder' || jsonb_build_object('reminders', jsonb_build_array(config->'reminder'))
where config->'reminder' is not null
;
