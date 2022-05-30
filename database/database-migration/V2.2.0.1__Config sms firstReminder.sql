insert into config (updatedby,segment,field,value)
values
('Annie','sms','firstReminder','{"en": "SMS content in English", "fi":"SMS sisältö suomeksi"}')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();

insert into config (updatedby,segment,field,value)
values
('Annie','sms','firstReminderDelay','4320')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
