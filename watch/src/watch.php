<?php
/* watch.php
 * Copyright (c) 2021-2022 Annie Advisor
 * All rights reserved.
 * Contributors:
 *  Lauri Jokipii <lauri.jokipii@annieadvisor.com>
 *
 * "Watchdog" for instance.
 *
 * NB! Meant to be used only via php-cli (not http)!
 */

// this script is actually NOT meant to be called via HTTP
if (php_sapi_name() != "cli") {
  print("<h1>hello</h1>");
  exit;
}

//: setup block
{

  require_once 'my_app_specific_library_dir/settings.php';

  // save the initiation time, local time (after settings -> time zone)
  $runminute = date("i");
  // calculate to previous 5 (nb! hardcoded 5 here)
  $runminute = str_pad(floor($runminute/5)*5, 2, "0", STR_PAD_LEFT);
  $runtime = date("H").$runminute;

  # Make sure of exection time window locally,
  # unless manual run
  $manualrun=false;
  # look for "--manual" argument
  for ($i=0; $i < $argc; $i++) {
    if ((string)$argv[$i] == "--manual") {
      $manualrun=true;
    }
  }

  require_once 'my_app_specific_library_dir/anniedb.php';
  $anniedb = new Annie\Advisor\DB($dbhost,$dbport,$dbname,$dbschm,$dbuser,$dbpass,$salt);
  /* + db */
  try {
    $dbh = new PDO("pgsql: host=$dbhost; port=$dbport; dbname=$dbname", $dbuser, $dbpass);
  } catch (PDOException $e) {
    die("Something went wrong while connecting to database: " . $e->getMessage() );
  }
  /* - db */

  # fetch configs
  $sql = "select value from $dbschm.config where segment='watchdog' and field='starttime'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $watchstart = isset($res->value) ? json_decode($res->value) : null;

  $sql = "select value from $dbschm.config where segment='watchdog' and field='endtime'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $watchend = isset($res->value) ? json_decode($res->value) : null;

  # Check acceptable time window
  if (($runtime<$watchstart || $runtime>$watchend) && !$manualrun) {
    //print("Out of execution time window. Exit.".PHP_EOL);
    exit;
  }

  $sql = "select value from $dbschm.config where segment='watchdog' and field='interval'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $watchinterval = isset($res->value) ? json_decode($res->value) : null;

  # Check matching interval
  if (($runminute % $watchinterval) != 0 && !$manualrun) {
    //print("Not on our interval. Exit.".PHP_EOL);
    exit;
  }

  // fetch additional configs
  $sql = "select value from $dbschm.config where segment='ui' and field='language'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $lang = isset($res->value) ? json_decode($res->value) : null;

  require_once 'my_app_specific_library_dir/mail.php';
} // - setup block

//
// BEGIN
//

//: "provider & teacher reminder 1"
{
  $sql = "
  with conf as (
    select
      (mailfirstreminderdelay.value)::int delay
    , (mailfirstreminderdelay.value)::int/60/24 delaydays
    , (watchdoginterval.value)::int as interval
    , (watchdogstarttime.value#>>'{}')::text as starttime
    , (watchdogstarttime.value#>>'{}')::int/100 as starthour
    , (watchdogendtime.value#>>'{}')::text as endtime
    , (watchdogendtime.value#>>'{}')::int/100 as endhour
    from $dbschm.config watchdoginterval
    , $dbschm.config watchdogstarttime
    , $dbschm.config watchdogendtime
    , $dbschm.config mailfirstreminderdelay
    where 1=1
    and watchdoginterval.segment = 'watchdog' and watchdoginterval.field = 'interval'
    and watchdogstarttime.segment = 'watchdog' and watchdogstarttime.field = 'starttime'
    and watchdogendtime.segment = 'watchdog' and watchdogendtime.field = 'endtime'
    and mailfirstreminderdelay.segment = 'mail' and mailfirstreminderdelay.field = 'firstReminderDelay'
    limit 1 --max 1 row!
  )

  , users as (
    select annieuser.id, annieuser.meta, annieuser.iv
    , sn.id as supportneed
    , sn.updated
    , sn.survey
    , sn.contact
    , contact.contact as contactdata, contact.iv as contactiv
    from $dbschm.annieuser
    join $dbschm.annieusersurvey aus on aus.annieuser = annieuser.id
    join $dbschm.supportneedhistory sn on sn.survey = aus.survey
      and (aus.meta->'category'->sn.category)::boolean
    join $dbschm.contact on contact.id = sn.contact
    where annieuser.notifications = 'IMMEDIATE'
    and sn.status in ('1','2')
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneedhistory
      where contact = sn.contact and survey = sn.survey
    )
    union
    select annieuser.id, annieuser.meta, annieuser.iv
    , sn.id as supportneed
    , sn.updated
    , sn.survey
    , sn.contact
    , contact.contact as contactdata, contact.iv as contactiv
    from $dbschm.annieuser
    join $dbschm.contact on contact.annieuser = annieuser.id
    join $dbschm.supportneedhistory sn on sn.contact = contact.id
      -- drop those belonging to support providers
      and sn.id not in (
        select supportneedhistory.id
        from $dbschm.supportneedhistory
        join $dbschm.annieusersurvey on annieusersurvey.survey = supportneedhistory.survey
        where supportneedhistory.contact = contact.id
        and (annieusersurvey.meta->'category'->supportneedhistory.category)::boolean
      )
    cross join conf
    where annieuser.notifications = 'IMMEDIATE'
    and sn.status in ('1','2')
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneedhistory
      where contact = sn.contact and survey = sn.survey
    )
  )

  select users.id, users.meta, users.iv
  , users.supportneed
  , users.survey
  , users.contact, contactdata, contactiv
  , m1.body firstmessagecr, m1.iv firstmessageiv
  , m2.body lastmessagecr, m2.iv lastmessageiv
  from users
  join $dbschm.message m1 on m1.contact = users.contact and m1.survey = users.survey
    and m1.created = (
        select min(created) from $dbschm.message
        where contact=m1.contact and survey=m1.survey
        and status='DELIVERED' --sender ~ Annie
    )
  join $dbschm.message m2 on m2.contact = users.contact and m2.survey = users.survey
    and m2.created = (
        select max(created) from $dbschm.message
        where contact=m2.contact and survey=m2.survey
        and status='DELIVERED' --sender ~ Annie
    )
  cross join conf
  where 1=1
  -- has time passed reminder delay but no more than delay + wathdog interval
  -- nomore < updated < notyet
  -- outside of watchdog time window => next day first run
  and
  case
  -- check if updated matches time window
  when extract('hour' from users.updated) between conf.starthour and conf.endhour
  then
    case
    when users.updated between (now() - make_interval(mins := conf.delay + conf.interval)) and (now() - make_interval(mins := conf.delay))
    then true
    else false
    end  
  else -- updated NOT in time window
    case
    -- match to delay days
    when users.updated between (now() - make_interval(days := conf.delaydays + 1)) and (now() - make_interval(days := conf.delaydays))
    -- match to first run of the day (no certainty of exact runtime but lets trust that +-3 min time window will suffice)
     and conf.starttime between to_char(now() - make_interval(mins := 3),'HH24:MI') and to_char(now() + make_interval(mins := 3),'HH24:MI')
    then true
    else false
    end  
  end
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."provider & teacher reminder 1".PHP_EOL, count($remindees));

  if (count($remindees)>=1) {

    $sql = "select value from $dbschm.config where segment='mail' and field='firstReminder'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $mailcontent = isset($res->value) ? json_decode($res->value) : null;

    foreach ($remindees as $r) {
      $annieuser = (object)array("id" => $r->{'id'});
      $iv = base64_decode($r->{'iv'});
      $annieusermeta = json_decode(decrypt($r->{'meta'},$iv));
      if (isset($annieusermeta)
       && array_key_exists('email', $annieusermeta)
      ) {
        $annieuser->{'email'} = $annieusermeta->{'email'};
        $supportneed = $r->{'supportneed'};
        $survey = $r->{'survey'};
        $contact = $r->{'contact'};
        $contactiv = base64_decode($r->{'contactiv'});
        $contactdata = json_decode(decrypt($r->{'contactdata'},$contactiv));
        $firstmessageiv = base64_decode($r->{'firstmessageiv'});
        $firstmessage = decrypt($r->{'firstmessagecr'},$firstmessageiv);
        $lastmessageiv = base64_decode($r->{'lastmessageiv'});
        $lastmessage = decrypt($r->{'lastmessagecr'},$lastmessageiv);
        // actions
        if (isset($annieuser) && isset($mailcontent) && isset($lang)) {
          mailOnReminder($contactdata,$supportneed,$firstmessage,$lastmessage,array($annieuser),$mailcontent,$lang);
        }
      }
    }
  }
} // - "provider & teacher reminder 1"

//: "provider & teacher reminder 2"
{
  $sql = "
  with conf as (
    select
      (mailsecondreminderdelay.value)::int delay
    , (mailsecondreminderdelay.value)::int/60/24 delaydays
    , (watchdoginterval.value)::int as interval
    , (watchdogstarttime.value#>>'{}')::text as starttime
    , (watchdogstarttime.value#>>'{}')::int/100 as starthour
    , (watchdogendtime.value#>>'{}')::text as endtime
    , (watchdogendtime.value#>>'{}')::int/100 as endhour
    from $dbschm.config watchdoginterval
    , $dbschm.config watchdogstarttime
    , $dbschm.config watchdogendtime
    , $dbschm.config mailsecondreminderdelay
    where 1=1
    and watchdoginterval.segment = 'watchdog' and watchdoginterval.field = 'interval'
    and watchdogstarttime.segment = 'watchdog' and watchdogstarttime.field = 'starttime'
    and watchdogendtime.segment = 'watchdog' and watchdogendtime.field = 'endtime'
    and mailsecondreminderdelay.segment = 'mail' and mailsecondreminderdelay.field = 'secondReminderDelay'
    limit 1 --max 1 row!
  )

  , users as (
    select annieuser.id, annieuser.meta, annieuser.iv
    , sn.id as supportneed
    , sn.updated
    , sn.survey
    , sn.contact
    , contact.contact as contactdata, contact.iv as contactiv
    from $dbschm.annieuser
    join $dbschm.annieusersurvey aus on aus.annieuser = annieuser.id
    join $dbschm.supportneedhistory sn on sn.survey = aus.survey
      and (aus.meta->'category'->sn.category)::boolean
    join $dbschm.contact on contact.id = sn.contact
    where annieuser.notifications = 'IMMEDIATE'
    and sn.status in ('1','2')
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneedhistory
      where contact = sn.contact and survey = sn.survey
    )
    union
    select annieuser.id, annieuser.meta, annieuser.iv
    , sn.id as supportneed
    , sn.updated
    , sn.survey
    , sn.contact
    , contact.contact as contactdata, contact.iv as contactiv
    from $dbschm.annieuser
    join $dbschm.contact on contact.annieuser = annieuser.id
    join $dbschm.supportneedhistory sn on sn.contact = contact.id
      -- drop those belonging to support providers
      and sn.id not in (
        select supportneedhistory.id
        from $dbschm.supportneedhistory
        join $dbschm.annieusersurvey on annieusersurvey.survey = supportneedhistory.survey
        where supportneedhistory.contact = contact.id
        and (annieusersurvey.meta->'category'->supportneedhistory.category)::boolean
      )
    cross join conf
    where annieuser.notifications = 'IMMEDIATE'
    and sn.status in ('1','2')
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneedhistory
      where contact = sn.contact and survey = sn.survey
    )
  )

  select users.id, users.meta, users.iv
  , users.supportneed
  , users.survey
  , users.contact, contactdata, contactiv
  , m1.body firstmessagecr, m1.iv firstmessageiv
  , m2.body lastmessagecr, m2.iv lastmessageiv
  from users
  join $dbschm.message m1 on m1.contact = users.contact and m1.survey = users.survey
    and m1.created = (
        select min(created) from $dbschm.message
        where contact=m1.contact and survey=m1.survey
        and status='DELIVERED' --sender ~ Annie
    )
  join $dbschm.message m2 on m2.contact = users.contact and m2.survey = users.survey
    and m2.created = (
        select max(created) from $dbschm.message
        where contact=m2.contact and survey=m2.survey
        and status='DELIVERED' --sender ~ Annie
    )
  cross join conf
  where 1=1
  -- has time passed reminder delay but no more than delay + wathdog interval
  -- nomore < updated < notyet
  -- outside of watchdog time window => next day first run
  and
  case
  -- check if updated matches time window
  when extract('hour' from users.updated) between conf.starthour and conf.endhour
  then
    case
    when users.updated between (now() - make_interval(mins := conf.delay + conf.interval)) and (now() - make_interval(mins := conf.delay))
    then true
    else false
    end  
  else -- updated NOT in time window
    case
    -- match to delay days
    when users.updated between (now() - make_interval(days := conf.delaydays + 1)) and (now() - make_interval(days := conf.delaydays))
    -- match to first run of the day (no certainty of exact runtime but lets trust that +-3 min time window will suffice)
     and conf.starttime between to_char(now() - make_interval(mins := 3),'HH24:MI') and to_char(now() + make_interval(mins := 3),'HH24:MI')
    then true
    else false
    end  
  end
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."provider & teacher reminder 2".PHP_EOL, count($remindees));

  if (count($remindees)>=1) {

    $sql = "select value from $dbschm.config where segment='mail' and field='secondReminder'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $mailcontent = isset($res->value) ? json_decode($res->value) : null;

    foreach ($remindees as $r) {
      $annieuser = (object)array("id" => $r->{'id'});
      $iv = base64_decode($r->{'iv'});
      $annieusermeta = json_decode(decrypt($r->{'meta'},$iv));
      if (isset($annieusermeta)
       && array_key_exists('email', $annieusermeta)
      ) {
        $annieuser->{'email'} = $annieusermeta->{'email'};
        $supportneed = $r->{'supportneed'};
        $survey = $r->{'survey'};
        $contact = $r->{'contact'};
        $contactiv = base64_decode($r->{'contactiv'});
        $contactdata = json_decode(decrypt($r->{'contactdata'},$contactiv));
        $firstmessageiv = base64_decode($r->{'firstmessageiv'});
        $firstmessage = decrypt($r->{'firstmessagecr'},$firstmessageiv);
        $lastmessageiv = base64_decode($r->{'lastmessageiv'});
        $lastmessage = decrypt($r->{'lastmessagecr'},$lastmessageiv);
        // actions
        if (isset($annieuser) && isset($mailcontent) && isset($lang)) {
          mailOnReminder($contactdata,$supportneed,$firstmessage,$lastmessage,array($annieuser),$mailcontent,$lang);
        }
      }
    }
  }
} // - "provider & teacher reminder 2"

//: "survey end"
{
  // first end all on-going contactsurveys for ending survey
  $sql = "
  insert into $dbschm.contactsurvey (updatedby,contact,survey,status)
  select 'Annie' as updatedby
  , contact
  , survey
  , '100' as status
  from $dbschm.contactsurvey
  where survey in (
    select survey.id
    from $dbschm.survey
    where survey.status = 'IN PROGRESS'
    and now() > survey.endtime
  )
  -- does not exist (status=100)... which is last one (see below):
  and (contact,survey,status) not in (
    select cs.contact,cs.survey,cs.status
    from $dbschm.contactsurvey cs
    where cs.status = '100' --finished
  )
  -- last one for contact+survey:
  and (contact,survey,updated) in (
    select cs.contact,cs.survey,max(cs.updated)
    from $dbschm.contactsurvey cs
    group by cs.contact,cs.survey
  )
  group by contact, survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  //printf(date("Y-m-d H:i:s")."%4s  "."survey end".PHP_EOL, $sth->rowCount());

  // query data for followup
  $sql = "
  select id as survey
  , followup
  from $dbschm.survey
  where survey.status = 'IN PROGRESS'
  and now() > survey.endtime
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $surveys = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  foreach ($surveys as $surveyrow) {
    // AD-355 populate followup
    if (array_key_exists('followup', $surveyrow) && !empty($surveyrow->{'followup'})) {
      $ret = $anniedb->updateFollowupContacts($surveyrow->{'followup'}, 'Annie');
      if ($ret === 0) {
        printf(date("Y-m-d H:i:s")."%4s  "."followup %s of survey %s set".PHP_EOL, "", $surveyrow->{'followup'}, $surveyrow->{'survey'});
      } else if ($ret !== 1) {
        printf(date("Y-m-d H:i:s")."%4s  "."followup %s of survey %s setting resulted an ERROR".PHP_EOL, "", $surveyrow->{'followup'}, $surveyrow->{'survey'});
      }
    }
  } // - surveys

  // actually end the survey(s)
  $sql = "
  update $dbschm.survey
  set updatedby='Annie'
  , updated=now()
  , status='FINISHED'
  where status = 'IN PROGRESS'
  and now() > endtime
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."survey end".PHP_EOL, $sth->rowCount());
} // - "survey end"

//: "reminder"
{
  $sql = "
  select survey.id as survey
  , cs.contact
  , co.iv
  , co.contact as contactdata
  , first_reminder->>'message' as messagetemplate
  from $dbschm.survey
  join $dbschm.contactsurvey cs on cs.survey=survey.id
  join $dbschm.contact co on co.id=cs.contact
  cross join jsonb_array_elements(survey.config->'reminders') first_reminder
  where survey.config is not null and survey.config->'reminders' is not null
  and survey.status = 'IN PROGRESS'
  and cs.status = '1' --started, not reminded
  -- last one for contact:
  and (cs.contact,cs.updated) in (
    select contact,max(updated)
    from $dbschm.contactsurvey
    where contact = cs.contact
    group by contact
  )
  -- choose first one (may be only one)
  and first_reminder->>'delay' = (
    select min(reminders->>'delay')
    from jsonb_array_elements(survey.config->'reminders') reminders
  )
  -- has reminder delay and time has passed for survey starttime (1 min extra for eliminating processing time)
  -- AD-186 survey.starttime -> cs.updated
  and cs.updated < now() - make_interval(hours := (first_reminder->>'delay')::int, mins := -1)
  ";
  //--=> reminder.php (does: send "reminder" to "contact" and insert contactsurvey status="2")
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."reminder".PHP_EOL, count($remindees));
  $survey = null;
  $contact = null;
  $contactdata = null;
  $messagetemplate = null;

  // ...test if there is such info...
  // ...especially if there is not: nothing to do!
  if (count($remindees)>=1) {
    foreach ($remindees as $r) {
      print(date("Y-m-d H:i:s")." SEND REMINDER: ".$r->{'contact'}.PHP_EOL);
      $survey = $r->{'survey'};
      $contact = $r->{'contact'};
      $iv = base64_decode($r->{'iv'});
      $contactdata = json_decode(decrypt($r->{'contactdata'},$iv));
      $messagetemplate = $r->{'messagetemplate'};

      // actions
      require "reminder.php";
    }
  }
} // - "reminder"

//: "n-reminder"
{
  $sql = "
  select survey.id as survey
  , cs.contact
  , co.iv
  , co.contact as contactdata
  , next_reminder->>'message' as messagetemplate
  from $dbschm.survey
  join $dbschm.contactsurvey cs on cs.survey=survey.id
  join $dbschm.contact co on co.id=cs.contact
  cross join jsonb_array_elements(survey.config->'reminders') prev_reminder
  cross join jsonb_array_elements(survey.config->'reminders') next_reminder
  where survey.config is not null and survey.config->'reminders' is not null
  and survey.status = 'IN PROGRESS'
  -- this contactsurvey is the starting point for this contact+survey
  and cs.status = '1'
  -- there may not be anything other status than 1 or 2 before
  and (cs.contact, cs.survey) not in (
    select contact, survey
    from $dbschm.contactsurvey
    where contact = cs.contact
    and survey = cs.survey
    and status not in ('1','2') --status 1 and 2 are ok (not, not)
    group by contact, survey
  )
  -- make sure there are multiple reminders
  and (prev_reminder->>'delay')::int < (next_reminder->>'delay')::int
  -- choose latest already used reminder as previous reminder
  and prev_reminder->>'delay' = (
    select max(reminders->>'delay')
    from jsonb_array_elements(survey.config->'reminders') reminders
    where (reminders->>'delay')::int < (next_reminder->>'delay')::int
    and (reminders->>'delay')::int >= (prev_reminder->>'delay')::int
  )
  -- ...which is AFTER actual previous reminder
  and -- match the count of done reminders
  (
      select count(*)
      from $dbschm.contactsurvey c
      where c.contact = cs.contact
      and c.survey = cs.survey
      and c.status = '2' --reminded
  )
  = -- ... with available existing reminders
  (
      select count(*)
      from jsonb_array_elements(survey.config->'reminders') reminders
      where (reminders->>'delay')::int <= (prev_reminder->>'delay')::int
  )
  -- has reminder delay and time has passed for survey.starttime (1 min extra for eliminating processing time)
  -- AD-186 survey.starttime -> cs.updated
  and cs.updated < now() - make_interval(hours := (next_reminder->>'delay')::int, mins := -1)
  ";
  //--=> reminder.php (does: send "reminder" to "contact" and insert contactsurvey status="2")
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."n-reminder".PHP_EOL, count($remindees));
  $survey = null;
  $contact = null;
  $contactdata = null;
  $messagetemplate = null;

  // ...test if there is such info...
  // ...especially if there is not: nothing to do!
  if (count($remindees)>=1) {
    foreach ($remindees as $r) {
      print(date("Y-m-d H:i:s")." SEND n-REMINDER: ".$r->{'contact'}.PHP_EOL);
      $survey = $r->{'survey'};
      $contact = $r->{'contact'};
      $iv = base64_decode($r->{'iv'});
      $contactdata = json_decode(decrypt($r->{'contactdata'},$iv));
      $messagetemplate = $r->{'messagetemplate'};

      // actions
      require "reminder.php";
    }
  }
} // - "n-reminder"

//: "survey start"
{
  $sql = "
  select id as survey
  , config
  , contacts
  from $dbschm.survey
  where now() > starttime
  and config is not null
  and contacts is not null
  and status = 'SCHEDULED'
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $surveys = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."survey start".PHP_EOL, count($surveys));
  foreach ($surveys as $surveyrow) {
    $contacts = json_decode($surveyrow->{'contacts'});
    print(date("Y-m-d H:i:s")." INITIATE ".$surveyrow->{'survey'}.PHP_EOL);
    $survey = $surveyrow->{'survey'};
    $destinations = array();
    foreach ($contacts as $contact) {
      print(date("Y-m-d H:i:s")." INITIATE ".$survey." FOR ".$contact.PHP_EOL);
      $sql = "
      select contact as contactdata
      , iv
      from $dbschm.contact
      where id = :contact
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contact);
      $sth->execute();
      $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
      foreach ($rows as $rownum => $row) {
        $iv = base64_decode($row['iv']);
        $cd = json_decode(decrypt($row['contactdata'],$iv));
        array_push($destinations,$cd->{'phonenumber'});
      }
    }
    // actions
    require "initiate.php"; //provides: $messagetemplate

    // set survey in progress
    $sql = "
    update $dbschm.survey
    set updatedby='Annie'
    , updated=now()
    , status='IN PROGRESS'
    where id = :survey
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $survey);
    $sth->execute();

    // send email (per survey)
    // users with access (for email "To")
    $sql = "
    select id, meta, iv
    from $dbschm.annieuser
    where (superuser = true or id in (
      select annieuser
      from $dbschm.annieusersurvey
      where survey = :survey
      -- coordinator
      and meta is not null
      and (
        (meta->'coordinator' is not null and (meta->'coordinator')::boolean)
      )
    ))
    and coalesce(notifications,'DISABLED') != 'DISABLED'
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $survey);
    $sth->execute();
    $annieuserrows = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
    $annieusers = array();
    foreach ($annieuserrows as $au) {
      $annieuser = (object)array("id" => $au->{'id'});
      $iv = base64_decode($au->{'iv'});
      $annieusermeta = json_decode(decrypt($au->{'meta'},$iv));
      if (isset($annieusermeta) && array_key_exists('email', $annieusermeta)) {
        $annieuser->{'email'} = $annieusermeta->{'email'};
        array_push($annieusers, $annieuser);
      }
    }

    $sql = "select value from $dbschm.config where segment='mail' and field='initiate'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $mailcontent = isset($res->value) ? json_decode($res->value) : null;

    if (isset($surveyrow) && isset($destinations) && isset($annieusers) && count($annieusers)>0 && isset($mailcontent) && isset($lang)) {
      mailOnSurveyStart($surveyrow,$destinations,$annieusers,$messagetemplate,$mailcontent,$lang);
    }
  }
}
// - "survey start"

?>