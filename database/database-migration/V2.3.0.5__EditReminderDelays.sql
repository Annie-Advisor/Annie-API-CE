insert into config (updatedby,segment,field,value)
values
('Annie','mail','firstReminderDelay','2880'),
('Annie','mail','secondReminderDelay','5760'),
('Annie','sms','firstReminderDelay','5760')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
