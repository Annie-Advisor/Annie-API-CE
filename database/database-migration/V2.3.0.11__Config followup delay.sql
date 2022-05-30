insert into config (updatedby,segment,field,value)
values
('Annie','followup','delay','20160') -- minutes for 2 weeks
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
