insert into config (updatedby,segment,field,value)
values
('Annie','followup','duration','4320') -- minutes
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
