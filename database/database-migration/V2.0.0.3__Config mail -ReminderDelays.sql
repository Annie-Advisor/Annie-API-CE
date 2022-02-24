insert into config (updatedby,segment,field,value)
values
('Annie','mail','firstReminderDelay','4320'),
('Annie','mail','secondReminderDelay','10080')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
