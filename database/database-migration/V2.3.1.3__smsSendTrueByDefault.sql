insert into config (updatedby,segment,field,value)
values
('Annie','ui','smsSendEnabled','true')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
