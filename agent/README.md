# Annie. Agent

## Description

Collection of command line executed PHP scripts that will do data maintenace for Annie database.

## Tasks

Agent has tasks:

- delete all data from a given student
- delete students with no links to other data
- delete a given survey

### Delete all data from a given student

(`php delete-contact.php -c|--contact contact.id`)

With a mandatory direct `contact.id` value as argument deletes all data from database related to that contact (student).


### Delete students with no links to other data

(`php delete-contacts-no-data.php`)

Searches for contacts (students) that have no data linked to it and deletes them all.


### Delete a given survey

(`php delete-survey.php -s|--survey survey.id`)

With a mandatory direct `survey.id` value as argument deletes all data from database related to that survey (campaign).

