DROP VIEW IF EXISTS usageright_teacher;
DROP VIEW IF EXISTS usageright_provider;
DROP VIEW IF EXISTS usageright_coordinator;
DROP VIEW IF EXISTS usageright_superuser;

-- superuser
DROP VIEW IF EXISTS usageright_superuser;
CREATE VIEW usageright_superuser AS
    SELECT id as annieuser
    FROM annieuser
    WHERE superuser::boolean = true::boolean
;

-- coordinator of survey
DROP VIEW IF EXISTS usageright_coordinator;
CREATE VIEW usageright_coordinator AS
    select aus.annieuser
    , aus.survey
    from annieusersurvey aus
    where aus.meta is not null and aus.meta->'coordinator' is not null
    and (aus.meta->'coordinator')::boolean = true::boolean
;
-- provider of survey + category
DROP VIEW IF EXISTS usageright_provider;
CREATE VIEW usageright_provider AS
    select aus.annieuser
    , aus.survey
    , j.key category
    from annieusersurvey aus
    cross join jsonb_each(aus.meta->'category') j
    where aus.meta is not null and aus.meta->'category' is not null
    and j.value::boolean = true::boolean
;
-- teacher of contact
DROP VIEW IF EXISTS usageright_teacher;
CREATE VIEW usageright_teacher AS
    --AD-260 responsible teacher
    select contact.annieuser
    , contact.id as teacherfor
    from contact
    where contact.annieuser is not null
;
