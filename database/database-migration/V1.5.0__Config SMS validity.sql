INSERT INTO config (updatedby,segment,field,value)
VALUES
('Annie','sms','validity','1440')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
