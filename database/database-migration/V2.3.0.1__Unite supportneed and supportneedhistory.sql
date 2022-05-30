INSERT INTO supportneed (id,updated,updatedby,contact,category,status,survey,userrole)
SELECT id,updated,updatedby,contact,category,status,survey,userrole
FROM supportneedhistory
WHERE id NOT IN (select id from supportneed)
;
CREATE INDEX IF NOT EXISTS supportneed_contact_survey_idx ON supportneed (contact, survey);

ALTER TABLE supportneedcomment ADD CONSTRAINT supportneedcomment_supportneed_fk FOREIGN KEY (supportneed) REFERENCES supportneed(id) ON DELETE CASCADE;
ALTER TABLE supportneedcomment DROP CONSTRAINT supportneedcomment_supportneedhistory_fk;

DROP TABLE supportneedhistory;
