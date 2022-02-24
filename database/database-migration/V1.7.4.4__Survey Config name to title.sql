UPDATE survey
SET updated=now(), updatedby='Annie'
,config = config - 'name' || jsonb_build_object('title',
  case
  when coalesce(config->>'title','')!='' then config->>'title'
  when coalesce(config#>>'{name,fi}','')!='' then config#>>'{name,fi}'
  when coalesce(config#>>'{name,en}','')!='' then config#>>'{name,en}'
  else 'Survey scheduled for '||survey.starttime
  end
)
;
