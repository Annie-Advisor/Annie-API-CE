-- Version v202104
CREATE TABLE IF NOT EXISTS contact (
    id varchar(64) NOT NULL,
    contact text NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar NOT NULL default 'Annie',
    iv text NULL,
    CONSTRAINT contact_pk PRIMARY KEY (id)
);
comment on column contact.updated is 'Default now()';
comment on column contact.updatedby is 'Default Annie because of internal use';

CREATE TABLE IF NOT EXISTS survey (
    id varchar(64) NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar NOT NULL,
    starttime timestamptz NOT NULL,
    endtime timestamptz NOT NULL,
    config jsonb NULL,
    status varchar(20) NULL,
    contacts jsonb NULL,
    CONSTRAINT survey_pk PRIMARY KEY (id)
);
comment on column survey.updated is 'Default to now()';
COMMENT ON COLUMN survey.config IS 'JSON configuration for survey';
COMMENT ON COLUMN survey.contacts IS 'JSON list for contacts';

CREATE TABLE IF NOT EXISTS message (
    id varchar(100) NOT NULL, --PK
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    contact varchar(64) NOT NULL, --FK (cascade)
    body text NULL,
    sender text NULL,
    survey varchar(64) NOT NULL, --FK (nothing/stop)
    status varchar(20) NULL,
    created timestamptz NOT NULL DEFAULT now(),
    createdby varchar(100) NOT NULL,
    iv text NULL,
    CONSTRAINT message_pk PRIMARY KEY (id),
    CONSTRAINT message_contact_fk FOREIGN KEY (contact) REFERENCES contact(id) ON DELETE CASCADE,
    CONSTRAINT message_survey_fk FOREIGN KEY (survey) REFERENCES survey(id)
);
comment on column message.updated is 'Default now()';
comment on column message.contact is 'Reference to Contact.ID';
comment on column message.survey is 'Reference to Survey.ID';
comment on column message.created is 'Default now()';

CREATE TABLE IF NOT EXISTS supportneed (
    id serial NOT NULL, --PK
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    contact varchar(64) NOT NULL, --FK (cascade)
    category varchar(100) NOT NULL,
    status varchar(100) NULL,
    survey varchar(64) NULL, --FK (nothing/stop)
    userrole varchar(100) NULL,
    CONSTRAINT supportneed_pk PRIMARY KEY (id),
    CONSTRAINT supportneed_contact_fk FOREIGN KEY (contact) REFERENCES contact(id) ON DELETE CASCADE,
    CONSTRAINT supportneed_survey_fk FOREIGN KEY (survey) REFERENCES survey(id)
);
comment on column supportneed.updated is 'Default now()';
comment on column supportneed.contact is 'Reference to Contact.ID';
comment on column supportneed.survey is 'Reference to Survey.ID';

CREATE TABLE IF NOT EXISTS supportneedhistory (
    id int4 NOT NULL, --Not SERIAL!
    updated timestamptz NOT NULL,
    updatedby varchar(100) NOT NULL,
    contact varchar(64) NOT NULL, --FK (cascade)
    category varchar(100) NOT NULL,
    status varchar(100) NULL,
    survey varchar(64) NULL, --NB! NOT FK
    userrole varchar(100) NULL,
    CONSTRAINT supportneedhistory_pk PRIMARY KEY (id),
    CONSTRAINT supportneedhistory_contact_fk FOREIGN KEY (contact) REFERENCES contact(id) ON DELETE CASCADE
);
comment on column supportneedhistory.id is 'Data type same as Supportneed.ID but not serial';
comment on column supportneedhistory.contact is 'Reference to Contact.ID';

CREATE TABLE IF NOT EXISTS supportneedcomment (
    id serial NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    supportneed int4 NOT NULL, --FK (cascade)
    body text NOT NULL,
    iv text NULL,
    CONSTRAINT supportneedcomment_pk PRIMARY KEY (id),
    CONSTRAINT supportneedcomment_supportneedhistory_fk FOREIGN KEY (supportneed) REFERENCES supportneedhistory(id) ON DELETE CASCADE
);
comment on column supportneedcomment.updated is 'Default now()';
comment on column supportneedcomment.supportneed is 'Reference to SupportneedHistory.ID';

CREATE TABLE IF NOT EXISTS codes (
    id serial NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL DEFAULT 'Annie',
    codeset varchar(100) NOT NULL,
    code varchar(100) NOT NULL,
    value jsonb NULL,
    validuntil date NULL,
    CONSTRAINT codes_pk PRIMARY KEY (id)
);
comment on column codes.updated is 'Default now()';
comment on column codes.updatedby is 'Default Annie because of internal use';
comment on column codes.value is 'All of this particular code key and value as JSON';
comment on column codes.validuntil is 'Date to which code is valid';

CREATE TABLE IF NOT EXISTS contactsurvey (
    id serial NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL DEFAULT 'Annie',
    contact varchar(64) NOT NULL, --FK (cascade)
    survey varchar(64) NULL, --FK (cascade)
    status varchar(100) NOT NULL,
    CONSTRAINT contactsurvey_pk PRIMARY KEY (id),
    CONSTRAINT contactsurvey_contact_fk FOREIGN KEY (contact) REFERENCES contact(id) ON DELETE CASCADE,
    CONSTRAINT contactsurvey_survey_fk FOREIGN KEY (survey) REFERENCES survey(id) ON DELETE CASCADE
);
comment on column contactsurvey.updated is 'Default now()';
comment on column contactsurvey.updatedby is 'Default Annie because of internal use';
comment on column contactsurvey.contact is 'Reference to Contact.ID';
comment on column contactsurvey.survey is 'Reference to Survey.ID';

CREATE TABLE IF NOT EXISTS config (
    id serial NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    segment varchar(100) NOT NULL,
    field varchar(100) NOT NULL,
    value text NULL,
    CONSTRAINT config_pk PRIMARY KEY (id),
    CONSTRAINT config_un UNIQUE (segment, field)
);
comment on column config.updated is 'Default now()';
comment on column config.segment is 'Same as "section" in INI file';
comment on column config.field is 'Same as "key" in INI file';

CREATE TABLE IF NOT EXISTS annieuser (
    id varchar(64) NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    created timestamptz NOT NULL DEFAULT now(),
    createdby varchar(100) NOT NULL,
    meta text NULL,
    iv text NULL,
    validuntil date NULL,
    superuser boolean NOT NULL DEFAULT false,
    -- new in v202104 (nb! type change via AD-70):
    notifications varchar NOT NULL DEFAULT 'IMMEDIATE',
    CONSTRAINT annieuser_pk PRIMARY KEY (id)
);
comment on column annieuser.updated is 'Default now()';
comment on column annieuser.created is 'Default now()';

CREATE TABLE IF NOT EXISTS annieusersurvey (
    id serial NOT NULL,
    annieuser varchar(64) NOT NULL,
    survey varchar(64) NOT NULL,
    updated timestamptz NOT NULL DEFAULT now(),
    updatedby varchar(100) NOT NULL,
    meta jsonb NULL,
    CONSTRAINT annieusersurvey_pk PRIMARY KEY (id),
    CONSTRAINT annieusersurvey_un UNIQUE (annieuser,survey),
    CONSTRAINT annieusersurvey_user_fk FOREIGN KEY (annieuser) REFERENCES annieuser(id) ON DELETE CASCADE,
    CONSTRAINT annieusersurvey_survey_fk FOREIGN KEY (survey) REFERENCES survey(id) ON DELETE CASCADE
);
comment on column annieusersurvey.annieuser is 'Reference to Annieuser.ID';
comment on column annieusersurvey.survey is 'Reference to Survey.ID';
comment on column annieusersurvey.updated is 'Default now()';
comment on column annieusersurvey.meta IS 'JSON for meta data';
