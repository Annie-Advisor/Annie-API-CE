-- CONFIG
-- config/ui
insert into config (updatedby,segment,field,value)
values
('Annie','ui','itemsPerPage','50'),
('Annie','ui','studentTableOrder','["status","-updated","contactdata.lastname","contactdata.firstname"]'),
('Annie','ui','timeout','3'),
('Annie','ui','updateInterval','5'),
('Annie','ui','languages','{"en":"English", "fi":"Suomeksi", "sv":"Svenska"}'),
('Annie','ui','language','"en"'),
('Annie','ui','smsSendEnabled','true')
ON CONFLICT DO NOTHING;

-- config/i18n
insert into config (updatedby,segment,field,value)
values
('Annie','i18n','headline','{"title":{"fi":"Annie.","en":"Annie.","sv":"Annie."},"tag":{"fi":"Tuen tarpeen tunnistus","en":"Untangle Student Support","sv":"Hitta stödbehov i tid"},"logout":{"fi":"Kirjaudu ulos","en":"Sign out","sv":"Logga ut"},"config":{"fi":"Asetukset","en":"Settings","sv":"Inställningar","itemsPerPage":{"fi":"Rivejä sivulla","en":"Items per page","sv":"Rader per sida"},"updateInterval":{"fi":"Päivitystahti","en":"Update interval","sv":"Uppdateringsfrekvens"},"lang":{"fi":"Sivuston kieli","en":"Language","sv":"Språk"}},"body":{"fi":"opiskelijaa, jolla kaikki ok!","en":"students with everything ok!","sv":"studerande som klarar sig!"}}')
ON CONFLICT DO NOTHING;

-- config/columns
insert into config (updatedby,segment,field,value)
values
('Annie','columns','supportneed','{"category":{"i":66,"a":"category","t":"category","on":true,"fi":"Ratkaistava asia","en":"Category","sv":"Kategori"},"status":{"i":68,"a":"status","t":"supportNeedStatus","on":true,"fi":"Asian tila","en":"Status","sv":"Status"},"survey":{"i":65,"a":"survey","t":"survey","on":false,"fi":"Kyselykierros","en":"Survey","sv":"Enkät"},"userrole":{"i":67,"a":"userrole","t":"userrole","on":false,"fi":"Käyttäjäryhmä","en":"User role","sv":"Roll"}}'),
('Annie','columns','contact','{"phonenumber":{"i":12,"a":"phonenumber","t":"text","on":false,"fi":"Puhelinnumero","en":"Phone number","sv":"Telefonnummer"},"studentid":{"i":10,"a":"studentid","t":"text","on":false,"fi":"Opiskelija ID","en":"Student ID","sv":"Student ID"},"studentnumber":{"i":11,"a":"studentnumber","t":"text","on":false,"fi":"Opiskelijanumero","en":"Student number","sv":"Studerandenummer"},"firstname":{"i":15,"a":"firstname","t":"text","on":true,"fi":"Etunimi","en":"First name","sv":"Förnamn"},"lastname":{"i":16,"a":"lastname","t":"text","on":true,"fi":"Sukunimi","en":"Last name","sv":"Efternamn"},"birthdate":{"i":17,"a":"birthdate","t":"date","on":false,"fi":"Syntymäaika","en":"Birthdate","sv":"Födelsedatum"},"degree":{"i":31,"a":"degree","t":"text","on":true,"fi":"Tutkinto","en":"Degree","sv":"Examen"},"studyrightstartdate":{"i":21,"a":"studyrightstartdate","t":"date","on":true,"fi":"Opinto-oikeuden alkamispäivä","en":"Study right start date","sv":"Studierättens startdatum"},"email":{"i":13,"a":"email","t":"text","on":false,"fi":"Sähköpostiosoite","en":"Email","sv":"Epost"},"studystartdate":{"i":23,"a":"studystartdate","t":"date","on":false,"fi":"Opiskelun alkamispäivä","en":"Study start date","sv":"Studiernas startdatum"},"studystarttime":{"i":24,"a":"studystarttime","t":"time","on":false,"fi":"Opiskelun alkamiskellonaika","en":"Study start time","sv":"Studiernas starttid"},"studystartaddress":{"i":22,"a":"studystartaddress","t":"text","on":false,"fi":"Opiskelun aloitusosoite","en":"Study start address","sv":"Studiernas startplats"},"location":{"i":33,"a":"location","t":"text","on":true,"fi":"Toimipaikka","en":"Location","sv":"Kampus"},"group":{"i":32,"a":"group","t":"text","on":false,"fi":"Ryhmä","en":"Group","sv":"Grupp"}}')
ON CONFLICT DO NOTHING;

-- config/survey
insert into config (updatedby,segment,field,value)
values
('Annie','survey','lastMessageDelay','6')
ON CONFLICT DO NOTHING;

-- config/watchdog
insert into config (updatedby,segment,field,value)
values
('Annie','watchdog','starttime','"0800"'),
('Annie','watchdog','endtime','"2000"'),
('Annie','watchdog','interval','15')
ON CONFLICT DO NOTHING;


-- CODES
insert into codes (codeset,code,value)
values
-- category
('category','W','{"fi":"Viesti ei mennyt perille","en":"Delivery failed"}'),
('category','X','{"fi":"Opiskelija ei vastannut","en":"No reply"}'),
('category','Y','{"fi":"Opiskelijan aloite","en":"Student initiated"}'),
('category','Z','{"fi":"Tuntematon","en":"Unknown"}'),

-- supportNeedStatus
('supportNeedStatus','1','{"fi":"Uusi","en":"New"}'),
('supportNeedStatus','2','{"fi":"Käsittelyssä","en":"In progress"}'),
('supportNeedStatus','100','{"fi":"Ratkaistu","en":"Resolved"}'),
('supportNeedStatus','-1','{"en":"Error","es":"Error","fi":"Virhe","sv":"Fel"}')
ON CONFLICT DO NOTHING;


-- ANNIEUSER
insert into annieuser (id,updatedby,createdby,superuser)
values
('miska.noponen@annieadvisor.com','Annie','Annie',true),
('emilia.kuuskoski@annieadvisor.com','Annie','Annie',true)
ON CONFLICT DO NOTHING;
