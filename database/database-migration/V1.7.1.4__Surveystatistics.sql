CREATE TABLE IF NOT EXISTS surveystatistics (
    surveyid varchar(64) NOT NULL,
    surveyname text,
    surveystarttime timestamptz,
    surveyendtime timestamptz,
    --
    contactid varchar(64) NOT NULL,
    contactdegree text,
    contactgroup text,
    contactlocation text,
    contactcustomtext text,
    contactcustomkey text,
    --
    delivered int,
    responded int,
    responsetime int,
    supportneed int,
    remindercount int,
    messagesreceived int,
    messagessent int,
    --
    supportneedcategory text NOT NULL,
    supportneedcategoryname text,
    supportneedstatus text,
    supportneedstatusname text,
    --
    CONSTRAINT surveystatistics_pk PRIMARY KEY (surveyid,contactid,supportneedcategory)
);
