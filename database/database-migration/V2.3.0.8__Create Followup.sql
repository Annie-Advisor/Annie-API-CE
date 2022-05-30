CREATE TABLE IF NOT EXISTS followup (
    id serial NOT NULL, --PK
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar NOT NULL,
    supportneed int4 NOT NULL, --FK (cascade)
    status varchar(100) NOT NULL,
    result varchar(100) NULL,
    CONSTRAINT followup_pk PRIMARY KEY (id),
    CONSTRAINT followup_supportneed_fk FOREIGN KEY (supportneed) REFERENCES supportneed(id) ON DELETE CASCADE
);
COMMENT ON COLUMN followup.supportneed IS 'Reference to Supportneed.ID';
