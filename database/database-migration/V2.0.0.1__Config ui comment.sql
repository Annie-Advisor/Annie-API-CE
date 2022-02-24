insert into config (updatedby,segment,field,value)
values
('Annie','ui','comment','
["Hoidossa muuta kautta", "Annoin ohjeita", "Soitin opiskelijalle", "Tapaaminen sovittu", "Laitoin viestiä", "Lähetin sähköpostia", "Otin yhteyttä toisessa kanavassa", "Ohjasin opiskelijan eteenpäin", "Opiskelijaa ei tavoitettu"]
')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
