INSERT INTO config (updatedby,segment,field,value)
VALUES
('Annie','bot','defaultResponseSMS','{"message":"Viesti vastaanotettu. Your message has been received."}')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
