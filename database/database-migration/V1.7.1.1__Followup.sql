CREATE TABLE IF NOT EXISTS followup (
    id serial NOT NULL, --PK
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar NOT NULL,
    created timestamptz NOT NULL DEFAULT now(),
    createdby varchar(100) NOT NULL,
    survey varchar(64) NOT NULL, --FK (cascade)
    starttime timestamptz NOT NULL,
    endtime timestamptz NOT NULL,
    status varchar(20) NULL,
    config jsonb NULL,
    contacts jsonb NULL,
    CONSTRAINT followup_pk PRIMARY KEY (id),
    CONSTRAINT followup_survey_fk FOREIGN KEY (survey) REFERENCES survey(id) ON DELETE CASCADE
);
COMMENT ON COLUMN followup.survey IS 'Reference to Survey.ID';
COMMENT ON COLUMN followup.config IS 'JSON configuration for followup';
COMMENT ON COLUMN followup.contacts IS 'JSON list for contacts';
