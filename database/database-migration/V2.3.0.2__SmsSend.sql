insert into config (updatedby,segment,field,value)
values
('Annie','ui','smsSendEnabled','false')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
