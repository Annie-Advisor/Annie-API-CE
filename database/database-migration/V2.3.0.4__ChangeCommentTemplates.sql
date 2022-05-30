insert into config (updatedby,segment,field,value)
values
('Annie','ui','comment','["Ohjeita opiskelijalle", "Soitto opiskelijalle", "Tapaamisen sopiminen", "Opiskelijan ohjaus eteenpäin", "Yhteydenotto toisessa kanavassa", "Opiskelijaa ei tavoitettu", "Hoidetaan muuta kautta"]')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
