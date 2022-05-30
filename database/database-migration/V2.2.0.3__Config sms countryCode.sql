insert into config (updatedby,segment,field,value)
values
('Annie','sms','countryCode','"+358"')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
