<?php
/* watch.php
 * Copyright (c) 2021 Annie Advisor
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
  $sql = "select value from $dbschm.config where segment='mail' and field='dailyDigestSchedule'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $maildailydigestschedule = isset($res->value) ? json_decode($res->value) : null;

  require_once 'my_app_specific_library_dir/mail.php';
} // - setup block

//
// BEGIN
//

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

  // send email
  // query data for email placeholders
  $sql = "
  select id as survey
  , followup
  , config
  , (
    select count(distinct contact)
    from $dbschm.contactsurvey
    where survey = survey.id
    -- last one for contact+survey:
    and (contact,survey,updated) in (
      select cs.contact,cs.survey,max(cs.updated)
      from $dbschm.contactsurvey cs
      group by cs.contact,cs.survey
    )
  ) as contactcount
  , (
    select count(distinct contact)
    from $dbschm.supportneed
    where survey = survey.id
  ) as supportneedcount
  , (select value from $dbschm.codes where codeset='supportNeedStatus' and code='1') as supportneedstatus1
  , (select value from $dbschm.codes where codeset='supportNeedStatus' and code='2') as supportneedstatus2
  , (select value from $dbschm.codes where codeset='supportNeedStatus' and code='100') as supportneedstatus100
  from $dbschm.survey
  where survey.status = 'IN PROGRESS'
  and now() > survey.endtime
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $surveys = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  foreach ($surveys as $surveyrow) {
    // supportneeds by category (for email table data)
    $sql = "
    select category
    , coalesce(
        (select value from $dbschm.codes where codeset='category' and code=category)
        ,jsonb_build_object('fi',category,'sv',category,'en',category)
      ) as categoryname
    , sum(case when status='1' then 1 else 0 end) as supportneedstatus1count
    , sum(case when status='2' then 1 else 0 end) as supportneedstatus2count
    , sum(case when status='100' then 1 else 0 end) as supportneedstatus100count
    from $dbschm.supportneed
    where survey=:survey
    group by category
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $surveyrow->{'survey'});
    $sth->execute();
    $supportneeds = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
    // users with access (for email "To")
    $sql = "
    select id, meta, iv
    from $dbschm.annieuser
    where (superuser = true or id in (
      select annieuser
      from $dbschm.annieusersurvey
      left join jsonb_each(meta->'category') j on 1=1
      where survey = :survey
      -- either coordinator or support provider (via category) is set
      and meta is not null
      and (
        (meta->'coordinator' is not null and (meta->'coordinator')::boolean)
        or
        (meta->'category' is not null and j.value::boolean = true)
      )
    ))
    and coalesce(notifications,'DISABLED') != 'DISABLED'
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $surveyrow->{'survey'});
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

    $sql = "select value from $dbschm.config where segment='mail' and field='surveyEnd'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $mailcontent = isset($res->value) ? json_decode($res->value) : null;

    $sql = "select value from $dbschm.config where segment='ui' and field='language'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $lang = isset($res->value) ? json_decode($res->value) : null;

    if (isset($surveyrow) && isset($supportneeds) && isset($annieusers) && count($annieusers)>0 && isset($mailcontent) && isset($lang)) {
      mailOnSurveyEnd($surveyrow,$supportneeds,$annieusers,$mailcontent,$lang);
    }

    // AD-275 Survey end notification for teachers
    $sql = "
    select annieuser.id
    , annieuser.meta
    , annieuser.iv
    from $dbschm.supportneed
    join $dbschm.contact on contact.id=supportneed.contact
    join $dbschm.annieuser on annieuser.id=contact.annieuser
    where supportneed.survey=:survey
    and coalesce(annieuser.notifications,'DISABLED') != 'DISABLED'
    group by annieuser.id, annieuser.meta, annieuser.iv
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $surveyrow->{'survey'});
    $sth->execute();
    $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));

    foreach ($annieusers as $au) {
      $annieuser = (object)array("id" => $au->{'id'});
      $iv = base64_decode($au->{'iv'});
      $annieusermeta = json_decode(decrypt($au->{'meta'},$iv));
      if (isset($annieusermeta)
       && array_key_exists('email', $annieusermeta)
       && array_key_exists('firstname', $annieusermeta)
      ) {

        $annieuser->{'email'} = $annieusermeta->{'email'};
        $annieuser->{'firstname'} = $annieusermeta->{'firstname'};

        $sql = "
        select supportneed.category
        , coalesce(
            (select value from $dbschm.codes where codeset='category' and code=supportneed.category)
            ,jsonb_build_object('fi',supportneed.category,'sv',supportneed.category,'en',supportneed.category)
          ) as categoryname
        , sum(case when supportneed.status='1' then 1 else 0 end) as supportneedstatus1count
        , sum(case when supportneed.status='2' then 1 else 0 end) as supportneedstatus2count
        , sum(case when supportneed.status='100' then 1 else 0 end) as supportneedstatus100count
        from $dbschm.supportneed
        join $dbschm.contact on contact.id=supportneed.contact
        join $dbschm.annieuser on annieuser.id=contact.annieuser
        where supportneed.survey=:survey
        and annieuser.id=:annieuser
        group by supportneed.category
        ";
        $sth = $dbh->prepare($sql);
        $sth->bindParam(':survey', $surveyrow->{'survey'});
        $sth->bindParam(':annieuser', $annieuser->{'id'});
        $sth->execute();
        $supportneeds = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
        if (isset($supportneeds) && count($supportneeds)>0) {

          $sql = "select value from $dbschm.config where segment='mail' and field='surveyEndTeacher'";
          $sth = $dbh->prepare($sql);
          $sth->execute();
          $res = $sth->fetch(PDO::FETCH_OBJ);
          $mailcontent = isset($res->value) ? json_decode($res->value) : null;

          // reuse lang from above!

          if (isset($surveyrow) && isset($mailcontent) && isset($lang)) {
            mailOnSurveyEndTeacher($surveyrow,$supportneeds,$annieuser,$mailcontent,$lang);
          }
        } // - supportneeds
      } // - meta
    }// - annieusers

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
    require "initiate.php";

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
      left join jsonb_each(meta->'category') j on 1=1
      where survey = :survey
      -- either coordinator or support provider (via category) is set
      and meta is not null
      and (
        (meta->'coordinator' is not null and (meta->'coordinator')::boolean)
        or
        (meta->'category' is not null and j.value::boolean = true)
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

    $sql = "select value from $dbschm.config where segment='ui' and field='language'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $lang = isset($res->value) ? json_decode($res->value) : null;

    if (isset($surveyrow) && isset($destinations) && isset($annieusers) && count($annieusers)>0 && isset($mailcontent) && isset($lang)) {
      mailOnInitiate($surveyrow,$destinations,$annieusers,$mailcontent,$lang);
    }
  }
}
// - "survey start"

//: "daily digest"
if ($runtime==$maildailydigestschedule)
{
  // mail to "annieuser is responsible for"
  // nb! not for superusers
  $sql = "
  select annieuser.id, annieuser.meta, annieuser.iv
  , count(distinct supportneed.contact) as supportneedcount
  , count(distinct message.contact) as messagecount
  from $dbschm.annieuser
  join $dbschm.annieusersurvey aus on aus.annieuser = annieuser.id
  left join $dbschm.supportneed on supportneed.survey = aus.survey
    and (aus.meta->'category'->supportneed.category)::boolean
    and supportneed.updated >= now() - interval '1 days'
  left join $dbschm.message on message.survey = aus.survey
    and message.contact in (--AD-315 query supportneed separately without updated restriction!
        select su.contact
        from $dbschm.supportneed su
        where su.survey = aus.survey
        and (aus.meta->'category'->su.category)::boolean
    )
    -- AD-315 include only support process messages
    and message.context = 'SUPPORTPROCESS'
    and message.updated >= now() - interval '1 days'
  where annieuser.notifications = 'DAILYDIGEST'
  and (supportneed.contact is not null or message.contact is not null)
  group by annieuser.id, annieuser.meta, annieuser.iv

  union

  --AD-260 teachers
  select annieuser.id, annieuser.meta, annieuser.iv
  , count(distinct supportneed.contact) as supportneedcount
  , count(distinct message.contact) as messagecount
  from $dbschm.annieuser
  join $dbschm.contact on contact.annieuser = annieuser.id
  left join $dbschm.supportneed on supportneed.contact = contact.id
    -- drop those belonging to support providers
    and supportneed.id not in (
      select sn.id
      from $dbschm.supportneed sn
      join $dbschm.annieusersurvey aus on aus.survey = sn.survey
      where sn.contact = contact.id
      and (aus.meta->'category'->sn.category)::boolean
    )
    and supportneed.updated >= now() - interval '1 days'
  left join $dbschm.message on message.contact = contact.id
    -- drop those belonging to support providers
    and message.id not in (
      select msg.id
      from $dbschm.message msg
      join $dbschm.supportneed sn on sn.contact = msg.contact and sn.survey = msg.survey
      join $dbschm.annieusersurvey aus on aus.survey = sn.survey
      where msg.contact = contact.id
      and (aus.meta->'category'->sn.category)::boolean
    )
    -- AD-315 include only support process messages
    and message.context = 'SUPPORTPROCESS'
    and message.updated >= now() - interval '1 days'
  where annieuser.notifications = 'DAILYDIGEST'
  and (supportneed.contact is not null or message.contact is not null)
  group by annieuser.id, annieuser.meta, annieuser.iv
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $annieuserrows = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  $annieusers = array();
  foreach ($annieuserrows as $au) {
    $annieuser = (object)array(
      "id" => $au->{'id'},
      "supportneedcount" => $au->{'supportneedcount'},
      "messagecount" => $au->{'messagecount'}
    );
    $iv = base64_decode($au->{'iv'});
    $annieusermeta = json_decode(decrypt($au->{'meta'},$iv));
    if (isset($annieusermeta) && array_key_exists('email', $annieusermeta)) {
      $annieuser->{'email'} = $annieusermeta->{'email'};
      array_push($annieusers, $annieuser);
    }
  }

  printf(date("Y-m-d H:i:s")."%4s  "."daily digest $maildailydigestschedule".PHP_EOL, count($annieusers));

  $sql = "select value from $dbschm.config where segment='mail' and field='dailyDigest'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $mailcontent = isset($res->value) ? json_decode($res->value) : null;

  $sql = "select value from $dbschm.config where segment='ui' and field='language'";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $res = $sth->fetch(PDO::FETCH_OBJ);
  $lang = isset($res->value) ? json_decode($res->value) : null;

  if (isset($annieusers) && count($annieusers)>0 && isset($mailcontent) && isset($lang)) {
    mailOnDailyDigest($annieusers,$mailcontent,$lang);
  }
} // - "daily digest"

?>
