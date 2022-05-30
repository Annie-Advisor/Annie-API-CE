insert into config (updatedby,segment,field,value)
values
('Annie','survey','cancel','{"message": "Ok. Hyvää viikonjatkoa! 😊", "condition": "^[sS][tT][oO][pP]\\b"}'),
('Annie','survey','reminder','{"delay": 3, "message": "Löytyikö vaihtoehdoista sopivaa? 🤔\nVoit myös päättää keskustelun vastaamalla STOP"}')
ON CONFLICT (segment,field)
DO UPDATE SET value=EXCLUDED.value, updatedby=EXCLUDED.updatedby, updated=now();
