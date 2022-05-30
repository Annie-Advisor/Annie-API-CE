# Annie. Agent

## Description

Collection of scripts that will do data check-ups or data maintenance for Annie database.

## Tasks

Data check-ups:

 - GET `.../contact-duplicates.php`

 - GET `.../contact-missing-name.php`

 - GET `.../contact-missing-phonenumber.php`

 - GET `.../annieuser-duplicates.php`

 - GET `.../annieuser-missing-email.php`

 - GET `.../annieuser-missing-name.php`

 - GET `.../survey-has-many-supportneeds-in-one-branch.php`


Data maintenance tasks:

- POST `.../delete-contact.php/[contact.id]`

  With a mandatory direct `contact.id` value as argument deletes all data from database related to that contact (student).
  
- POST `.../delete-contacts-no-data.php`

  Searches for contacts (students) that have no data linked to it and deletes them all.

- POST `.../delete-survey.php/[survey.id]`

  With a mandatory direct `survey.id` value as argument deletes all data from database related to that survey (campaign).
