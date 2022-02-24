ALTER TABLE survey ADD followup varchar(64) NULL;
COMMENT ON COLUMN survey.followup IS 'Reference to Survey.ID';

ALTER TABLE survey ADD CONSTRAINT survey_followup_fk FOREIGN KEY (followup) REFERENCES survey(id) ON DELETE CASCADE ON UPDATE CASCADE;
