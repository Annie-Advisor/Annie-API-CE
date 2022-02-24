# Annie. API

Application Programming Interface (API) to serve the needs of Annie UI etc.

## Requirements

- Annie. Library

## API endpoints

In the following are endpoints with their HTTP methods and arguments in this API. In the list:
- `key` means last part of HTTP URL (.../api/key) which typically refers to database table row ID (exceptions are mentioned separately),
- `getarr` means HTTP URL GET arguments or parameters with the "?" and "&" separators and many values for one key can be given meaning array/list of values, and
- `input` means HTTP request body for PUT and POST methods.
  - JSON fields _updated_ and _created_ are optional arguments with defaulting to current date-time
  - JSON fields _updatedby_ and _createdby_ are optional arguments with defaulting to current user

Arguments displayed with surrounding "[" and "]" are optional.

### Database structure based API endpoints

These API endpoints are meant for accessing in a one per database table manner. So knowledge of database structure is assumed (not given here; see [database](../database) for more info)

#### annieuser.php

Methods:

- POST [key] input
- GET [key]
- PUT key input
- DELETE key

Input:

```JSON
{
  "id":"id-string",
  "meta":"json-string",
  "superuser":"boolean",
  "notifications":"string",
  "validuntil":"datetime-string",
  "updated":"datetime-string",
  "updatedby":"string",
  "created":"datetime-string",
  "createdby":"string"
}
```

Examples:

```JSON
HTTP POST /api/annieuser.php/
{
  "id": "annieuser@annieadvisor.com",
  "meta": {
    "firstname": "Annie",
    "lastname": "User",
    "email": "annieuser@annieadvisor.com"
  },
  "superuser": false,
  "notifications": "IMMEDIATE"
}
```

```JSON
HTTP GET /api/annieuser.php/annieuser@annieadvisor.com
```

```JSON
HTTP PUT /api/annieuser.php/annieuser@annieadvisor.com
{
  "meta": {
    "firstname": "Annie",
    "lastname": "User",
    "email": "annieuser@annieadvisor.com"
  },
  "superuser": false,
  "notifications": "DISABLED"
}
```

```JSON
HTTP DELETE /api/annieuser.php/annieuser@annieadvisor.com
```

#### unsubscribe.php (v2)

Update authenticated users `annieuser.notifications` value. Defaults to DISABLED.

Methods:

- POST [key] input

Input:

```JSON
{
  "notifications":"string"
}
```

Examples:

```JSON
HTTP POST /api/v2/unsubscribe.php/
{
  "notifications": "DISABLED"
}
```

#### annieusersurvey.php

This API endpoint has a new way of handling data, batches. So data can be given in a list of updateable or insertable rows (in POST call only).

TODO: What should happen when even one row of a batch update/insert fails? - Rollback all. This is not yet implemented!

Methods:

- POST input
  - NB! _input_ can be a list
  - operation is UPSERT (update if exists, insert otherwise)
- GET [key] [getarr]
  - _getarr_ can have zero-to-many _survey_ or _id_ values
  - e.g. _?survey=SURVEYID1&survey=SURVEYID2&id=ID1&id=ID2_
- PUT key input
- DELETE key

Input:

```JSON
{
  "annieuser":"id-string",
  "survey":"id-string",
  "meta":"json-string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP POST /api/annieusersurvey.php/
[
  {
    "annieuser":"annieuser@annieadvisor.com",
    "survey":"anniesurvey",
    "meta":{
      "coordinator": true
    }
  }
]
```

```JSON
HTTP GET /api/annieusersurvey.php/123
HTTP GET /api/annieusersurvey.php/?survey=anniesurvey1&survey=anniesurvey2
```

```JSON
HTTP PUT /api/annieusersurvey.php/123
{
  "annieuser":"annieuser@annieadvisor.com",
  "survey":"anniesurvey",
  "meta": {
    "coordinator": false
  }
}
```

```JSON
HTTP DELETE /api/annieusersurvey.php/123
```

#### codes.php

NB! Has its own way of getting parameters via `codeset` + `code`.

Methods:

- POST input
  - upsert operation
  - NB! _input_ can be a list
- GET set key (.../codes.php/set/key)
  - _set_ is for _codeset_
  - _key_ is for _code_
- PUT input
  - NB! no _set_ or _key_ parameter
- DELETE set key

Input:

```JSON
{
  "codeset":"string",
  "code":"string",
  "value":"json-string",
  "validuntil":"datetime-string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP POST /api/codes.php/
[
  {
    "codeset": "anniecode",
    "code": "0",
    "value": {
      "en": "Everything is and isn't",
      "fi": "Kaikki on ja ei ole"
    }
  },
  {
    "codeset": "anniecode",
    "code": "1",
    "value": {
      "en": "Everything is",
      "fi": "Kaikki on"
    }
  },
  {
    "codeset": "anniecode",
    "code": "2",
    "value": {
      "en": "Everything is not",
      "fi": "Kaikki ei ole"
    }
  }
]
```

```JSON
HTTP GET /api/codes.php/anniecode/1
```

```JSON
HTTP PUT /api/codes.php/
[
  {
    "codeset": "anniecode",
    "code": "1",
    "value": {
      "en": "Everything just is",
      "fi": "Kaikki vaan on"
    }
  }
]
```

```JSON
HTTP DELETE /api/codes.php/anniecode/1
```

#### config.php (v2)

NB! Has its own way of getting parameters via `segment` + `field` very similar to `codes.php`.

Methods:

- GET segment field (.../config.php/segment/field)

Examples:

```JSON
HTTP GET /api/v2/config.php/
HTTP GET /api/v2/config.php/ui/
HTTP GET /api/v2/config.php/ui/language
```

#### supportneedcomment.php

The nature of support need comments are "permanent" like. So no updating.

NB! There's also a separate specialized API supportneedsupportneedcomments.php

Methods:

- POST input
- GET [key]
- DELETE key

Input:

```JSON
{
  "supportneed": 123,
  "body":"string",
  "updated":"datetime-string",
  "updatedby":"string"
}

```

Examples:

```JSON
HTTP POST /api/supportneedcomment.php/
{
  "supportneed": 123,
  "body": "Hello world"
}
```

```JSON
HTTP GET /api/supportneedcomment.php/123456
```

```JSON
HTTP DELETE /api/supportneedcomment.php/123456
```

#### contact.php

No data altering methods at all (PUT, POST, DELETE).

Methods:

- GET [key]

Input:

```JSON
-
```

Examples:

```JSON
HTTP GET /api/contact.php/anniecontact
```

#### contactsurvey.php

The nature of table contactsurvey if logging states. This means there is no need for updating the rows.

Methods:

- POST input
- GET [key]
- DELETE key

Input:

```JSON
{
  "survey":"id-string",
  "status":"string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP POST /api/contactsurvey.php/
{
  "contact": "anniecontact",
  "survey": "anniesurvey",
  "status": "1"
}
```

```JSON
HTTP GET /api/contactsurvey.php/123
```

```JSON
HTTP DELETE /api/contactsurvey.php/123
```

#### message.php

Methods:

- POST input
- GET [key]
  - NB! There is also a "specialized" API contactmessages.php
- PUT key input
  - update of _status_ only!
- DELETE key

Input:

```JSON
{
  "id":"id-string",
  "contact":"id-string",
  "body":"string",
  "sender":"string",
  "survey":"id-string",
  "context":"string",
  "status":"string",
  "updated":"datetime-string",
  "updatedby":"string",
  "created":"datetime-string",
  "createdby":"string"
}
```

Examples:

```JSON
HTTP POST /api/message.php/
{
  "contact": "anniecontact",
  "survey": "anniesurvey",
  "body": "Hello world",
  "status": "CREATED",
  "context": "SURVEY"
}
```

```JSON
HTTP GET /api/message.php/
```

```JSON
HTTP PUT /api/message.php/anniemessage
{
  "status": "FAILED"
}
```

```JSON
HTTP DELETE /api/message.php/anniemessage
```

#### supportneed.php

The structure of database table supportneed with its separate supportneedhistory table is one of a kind. This shows in the API code as well. Basically current solution in database and API is to rely on a _business key_ with combination of _contact_ and _survey_ values. The _business key_ is used in a primary key manner (but not actually) for "current status" table `supportneed`. No update of rows at all due to the nature of _supportneed_ table with separate _supportneedhistory_ table.

NB! Subject to change from version 2 onward where supportneed table is meant to be dropped and supportneedhistory table (with all of the changes per support need) will be the only table!

Version 2 addition to GET is to have latest information indicated with _current_=true via max id with business key contact+survey. Also DELETE method is removed

Methods:

- POST input
- GET [key]
  - NB! There is also a "specialized" API supportneedspage.php
- DEPRECATED (removed in v2): DELETE key

Input:

```JSON
{
  "contact":"id-string",
  "survey":"id-string",
  "category":"string",
  "status":"string",
  "userrole":"string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP POST /api/v2/supportneed.php/
{
  "contact": "anniecontact",
  "survey": "anniesurvey",
  "category": "categorycode",
  "status": "1"
}
```

```JSON
HTTP GET /api/v2/supportneed.php/
```

#### survey.php

Deletion of surveys is prohibited via API so no DELETE method available in purpose.

TODO: POST argument; database table id generation

Methods:

- POST key input
  - _key_ must be given since API or database does not generate id
  - nb! id can be given in POST body
- GET [key] [getarr]
  - _getarr_ can have zero-to-many _id_ (_key_) or _status_ values
- PUT key input

Input:

```JSON
{
  "starttime":"datetime-string",
  "endtime":"datetime-string",
  "config":"json-string",
  "status":"string",
  "contacts":"json-string",
  "followup":"id-string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP POST /api/survey.php/anniesurvey
{
  "starttime": "2021-12-01 12:34:56",
  "endtime": "2021-12-08 12:34:56",
  "config": {},
  "status": "DRAFT",
  "contacts": [],
  "followup": "anniesurvey1"
}
```

```JSON
HTTP GET /api/survey.php/anniesurvey
HTTP GET /api/survey.php/?id=anniesurvey1&id=anniesurvey2
HTTP GET /api/survey.php/?status=DRAFT
```

```JSON
HTTP PUT /api/survey.php/anniesurvey
{
  "starttime": "2021-12-01 12:34:56",
  "endtime": "2021-12-08 12:34:56",
  "config": {},
  "status": "SCHEDULED",
  "contacts": [],
  "followup": null
}
```

### Specialized

#### archive-survey-statistics.php

Perform survey archiving with inserting statics data to _surveystatistics_ from a similar query as in survey-report API, setting _survey.status_ to ARCHIVED and deleting related _supporneed_ rows.

Methods:

- GET [getarr]
  - _getarr_ can have zero-to-many _survey_ parameters with _survey.id_ as value
  - _getarr_ can have zero-to-one _archive_ parameters with "true" as acceptable value

Examples:

```JSON
HTTP GET /api/archive-survey-statistics.php/?survey=anniesurvey&archive=true
```

#### auth.php

Giving tools to do authentication and session refresh for UI. No DELETE or PUT methods available.

Methods:

- POST [input]
- GET [getarr]

Possible arguments (same for both _getarr_ or _input_):

- returnto
  - the address to return to after authentication process can be given
- source
  - for defining which authentication source to use
  - possible values vary between environments

Examples:

```JSON
HTTP GET /api/auth.php/?source=authentication-source
```

#### contactmessages.php

Return messages for contact (student).

- GET [key] [getarr]
  - _key_ is for _contact.id_
  - _getarr_ may have _impersonate_ with a value of _annieuser.id_

Examples:

```JSON
HTTP GET /api/message.php/anniecontact
```

#### contactcontactsurveys.php

Return latest contactsurvey for contact (student).

Methods:

- GET [key]
  - _key_ is for _contact.id_

Input:

```JSON
{
  "survey":"id-string",
  "status":"string",
  "updated":"datetime-string",
  "updatedby":"string"
}
```

Examples:

```JSON
HTTP GET /api/contactcontactsurveys.php/anniecontact
```

#### followup.php

Surveys now have a self-linking system via _followup_. This API handles actions regarding that link. No POST (insert) or DELETE methods available.

Methods:

- GET [key]
  - _key_ is for _survey.id_ and not for _survey.followup_
- PUT key
  - _key_ is for _survey.id_ and not for _survey.followup_
  - no input
  - copies _survey.contacts_ to another _survey_ referenced by its _followup_

Examples:

```JSON
HTTP GET /api/followup.php/anniesurvey
```

```JSON
HTTP PUT /api/followup.php/anniesurvey
```

#### metadata.php

Get quick statistics to show on UI. No insert, update or delete.

Methods:

- GET

Examples:

```JSON
HTTP GET /api/metadata.php/
```

#### resend.php

For resending a survey to selected contacts. In case of faulty phonenumbers in the previous attempt, for example. Only POST available. Basically the same as library/initiate.

Not yet attached to any UI but intention exists.

Methods:

- POST key input
  - _key_ is for _survey.id_
  - _input_ expects to have a list of _contact.id_

Input:

```JSON
[
  "id-string"
]
```

Examples:

```JSON
HTTP POST /api/resend.php/anniesurvey
[
  "anniecontact1",
  "anniecontact2"
]
```

#### sendsms.php

For sending SMS via UI. Only POST available.

TODO: Basic argument and input sanity checking (falls into "unmanaged" errors).

Methods:

- POST input
  - _input_ expects to have same input as for message API + "to" which is destination (phone number) for SMS

Input:

```JSON
{
  "to":"string"
  ,
  "contact":"id-string",
  "body":"string",
  "sender":"string",
  "survey":"id-string",
  "context":"string",
  "status":"string",
  "updated":"datetime-string",
  "updatedby":"string",
  "created":"datetime-string",
  "createdby":"string"
}
```

Examples:

```JSON
HTTP POST /api/sendsms.php/
{
  "to": "+358555123456",
  "contact": "anniecontact",
  "body": "Hello world",
  "survey": "anniesurvey"
}
```

#### annieusersupportneeds.php (v2)

Return all supportneeds for active user with survey data aside. Excluding supportneeds that reference archived survey.

- GET [key] [getarr]
  - _key_ is for _contact.id_
  - _getarr_
    - can have zero-to-many _category_, _status_, _survey_ or _userrole_ values
    - may have _impersonate_ with a value of _annieuser.id_

Examples:

```JSON
HTTP GET /api/annieusersupportneeds.php/anniecontact
HTTP GET /api/annieusersupportneeds.php/?category=Z&status=1&status=2&status=100
```

#### supportneedspage.php

Return all supportneeds paginated for active user with contact (student) data aside.

- GET [key] ["/history"] [getarr]
  - _key_ is for _contact.id_
  - "/history" is a deprecated legacy thing
  - _getarr_
    - can have zero-to-many _category_, _status_, _survey_ or _userrole_ values
    - may have _impersonate_ with a value of _annieuser.id_

Examples:

```JSON
HTTP GET /api/supportneedspage.php/anniecontact
HTTP GET /api/supportneedspage.php/?category=Z&status=1&status=2&status=100
```

#### supportneedsupportneedcomments.php

Get support need comments for given support need.

Methods:

- GET key

Input:

```JSON
{
  "supportneed": 123,
  "body":"string",
  "updated":"datetime-string",
  "updatedby":"string"
}

```

Examples:

```JSON
HTTP GET /api/supportneedsupportneedcomments.php/123456
```

#### survey-report.php

Generate survey report.

Methods_

- GET [getarr]
  - _getarr_ can have zero-to-many _survey_ parameters with _survey.id_ as value

Examples:

```JSON
HTTP GET /api/survey-report.php/?survey=anniesurvey1&survey=anniesurvey2
```
