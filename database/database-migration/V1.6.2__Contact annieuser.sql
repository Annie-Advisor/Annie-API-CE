ALTER TABLE contact ADD annieuser varchar(64) NULL;
ALTER TABLE contact ADD CONSTRAINT contact_annieuser_fk FOREIGN KEY (annieuser) REFERENCES annieuser(id);
COMMENT ON COLUMN contact.annieuser IS 'Reference to Annieuser.ID';
