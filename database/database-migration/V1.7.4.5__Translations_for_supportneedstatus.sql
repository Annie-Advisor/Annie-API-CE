insert into codes (updatedby,codeset,code,value)
values
('Annie','supportNeedStatus','1','{"en":"New","fi":"Uusi","sv":"Ny","es":"Noticias"}'),
('Annie','supportNeedStatus','2','{"en":"In progress","fi":"Käsittelyssä","sv":"Bearbetas","es":"En Progreso"}'),
('Annie','supportNeedStatus','100','{"en":"Resolved","fi":"Ratkaistu","sv":"Löst","es":"Resuelto"}')
ON CONFLICT (codeset,code)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
