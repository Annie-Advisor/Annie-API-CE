INSERT INTO config (updatedby,segment,field,value)
VALUES
('Annie','survey','reminder','
{
  "message":"Löytyikö vaihtoehdoista sopivaa? 🤔 \n Voit myös perua vastaamalla PERU",
  "delay":3
}
'),
('Annie','survey','cancel','
{
  "message":"Kiitos vastauksesta!",
  "condition": "^[pP][eE][rR][uU]\\b"
}
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
