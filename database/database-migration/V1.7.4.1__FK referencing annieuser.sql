-- Add "ON UPDATE CASCADE" to foreign keys (FK)
-- referencing ANNIEUSER table

-- "alter" FKs (drop + add)
ALTER TABLE contact
  DROP CONSTRAINT contact_annieuser_fk,
  ADD CONSTRAINT contact_annieuser_fk
    FOREIGN KEY (annieuser)
      REFERENCES annieuser(id)
      ON UPDATE CASCADE
;

-- nb! also RENAME this one
ALTER TABLE annieusersurvey
  DROP CONSTRAINT annieusersurvey_user_fk,
  ADD CONSTRAINT annieusersurvey_annieuser_fk
    FOREIGN KEY (annieuser)
      REFERENCES annieuser(id)
      ON DELETE CASCADE
      ON UPDATE CASCADE
;
