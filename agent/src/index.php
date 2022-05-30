<?php
/* index.php
 * Copyright (c) 2019-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * Index script for safety.
 *
 * NB! Authorization done via lower level IP restriction!
 */

?><!DOCTYPE html>
<html lang="en">
<head>
  <title>Annie. Agent</title>
</head>
<body>
  <h1>Annie. Agent</h1>

  <h2>Data check-ups</h2>
  <ul>
    <li><a href="contact-duplicates.php">contact-duplicates</a></li>
    <li><a href="contact-missing-name.php">contact-missing-name</a></li>
    <li><a href="contact-missing-phonenumber.php">contact-missing-phonenumber</a></li>
    <li><a href="annieuser-duplicates.php">annieuser-duplicates</a></li>
    <li><a href="annieuser-missing-email.php">annieuser-missing-email</a></li>
    <li><a href="annieuser-missing-name.php">annieuser-missing-name</a></li>
    <li><a href="survey-has-many-supportneeds-in-one-branch.php">survey-has-many-supportneeds-in-one-branch</a></li>
  </ul>

  <h2>Data maintenance tasks</h2>
  <ul>
    <li>
      POST delete-contact.php/[contact.id]<br>
      With a mandatory direct `contact.id` value as argument deletes all data from database related to that contact (student).
    </li>
    <li>
      POST delete-contacts-no-data.php<br>
      Searches for contacts (students) that have no data linked to it and deletes them all.
    </li>
    <li>
      POST delete-survey.php/[survey.id]<br>
      With a mandatory direct `survey.id` value as argument deletes all data from database related to that survey (campaign).
    </li>
  </ul>

</body>
</html>
