insert into config (updatedby,segment,field,value)
values
('Annie','mail','firstReminderDelay','1440'),
('Annie','mail','secondReminderDelay','4320')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
