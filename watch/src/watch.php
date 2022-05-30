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

//: "followup end"
// "When (follow.duration) has passed since followup start and followup status IS NOT 100, start followup closure process"
{
  // query ongoing followup(s) that were started followup.duration ago
  $sql = "
  SELECT fu.supportneed
  from $dbschm.followup fu
  where fu.status != '100' --not finished
  -- last one for supportneed (contact):
  and (fu.supportneed,fu.updated) in (
    select supportneed,max(updated)
    from $dbschm.followup
    group by supportneed
  )
  -- duration amount of time has passed since the START
  and exists (
      select 1
      from $dbschm.followup fustart
      join $dbschm.config on config.segment = 'followup' and config.field = 'duration' -- max 1 row
      where fustart.supportneed = fu.supportneed
      and fustart.updated < now() - make_interval(mins := (config.value#>>'{}')::int)
      and (fustart.supportneed,fustart.updated) in (
        select supportneed,min(updated) --nb! MIN e.g. START
        from $dbschm.followup
        group by supportneed
      )
  )
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $followups = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."followup end".PHP_EOL, count($followups));

  foreach ($followups as $followuprow) {
    $supportneedid = $followuprow->{'supportneed'};

    // add followupresult NORESPONSE to supportneed
    $newsupportneedid = $anniedb->insertSupportneed(json_decode(json_encode(array(
      "id" => $supportneedid, // for getting previous data
      "updatedby" => "Annie", // UI shows this
      "followupresult" => "NORESPONSE"
    ))));
    // end the followup
    $newfollowupid = $anniedb->insertFollowup(json_decode(json_encode(array(
      "supportneed" => $supportneedid,
      "status" => "100",
      "updatedby" => "Followup"
    ))));
  }
} // - "followup end"

//: "followup reminder"
{
  $sql = "
  select sn.id as supportneedid
  , sn.survey
  , co.id as contact
  , co.iv
  , co.contact as contactdata
  , co.optout
  , first_reminder->>'message' as messagetemplate
  from $dbschm.followup fu
  join $dbschm.supportneed sn on sn.id = fu.supportneed
  join $dbschm.config on config.segment = 'followup' and config.field = 'config'
  join $dbschm.contact co on co.id = sn.contact
  cross join jsonb_array_elements(config.value->sn.followuptype->'reminders') first_reminder
  where config.value is not null and config.value->sn.followuptype is not null and config.value->sn.followuptype->'reminders' is not null
  and fu.status = '1' --started, not reminded
  -- last one for supportneed (contact):
  and (fu.supportneed,fu.updated) in (
    select supportneed,max(updated)
    from $dbschm.followup
    group by supportneed
  )
  -- choose first one (may be only one)
  and first_reminder->>'delay' = (
    select min(reminders->>'delay')
    from jsonb_array_elements(config.value->sn.followuptype->'reminders') reminders
  )
  -- has reminder delay passed followup start time
  and fu.updated < now() - make_interval(hours := (first_reminder->>'delay')::int)
  and coalesce(co.optout,'9999-9-9') > now()
  ";
  //--=> followup-reminder.php (does: send "reminder" to "contact" and insert followup status="2" etc)
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."followup reminder".PHP_EOL, count($remindees));
  $supportneedid = null;
  $survey = null;
  $contact = null;
  $contactdata = null;
  $messagetemplate = null;

  // ...test if there is such info...
  // ...especially if there is not: nothing to do!
  if (count($remindees)>=1) {
    foreach ($remindees as $r) {
      print(date("Y-m-d H:i:s")." SEND FOLLOWUP REMINDER: ".$r->{'contact'}.PHP_EOL);
      $supportneedid = $r->{'supportneedid'};
      $survey = $r->{'survey'};
      $contact = $r->{'contact'};
      $iv = base64_decode($r->{'iv'});
      $contactdata = json_decode(decrypt($r->{'contactdata'},$iv));
      $messagetemplate = $r->{'messagetemplate'};

      // actions
      require "followup-reminder.php";
    }
  }
} // - "followup reminder"

//: "followup start"
// "When config followup.delay has passed since creation of a supportneed, followup process should be started"
{
  // followup
  /*
   * Expected variables:
   * - $settings
   * - $anniedb
   * - $followuptype
   * - $supportneedid
   * - $contactid
   * - $destination
   * - $category
   * - $lang
  */
  // query for followup(s)
  $sql = "
  SELECT req.id
  , sn.followuptype
  , sn.contact
  , sn.survey
  , sn.category
  FROM $dbschm.supportneed sn
  JOIN $dbschm.supportneed req ON req.survey = sn.survey AND req.contact = sn.contact
  JOIN $dbschm.config ON config.segment = 'followup' AND config.field = 'delay' -- max 1 row
  WHERE 1=1
  -- followup is set
  AND sn.followuptype IS NOT NULL
  -- latest supportneed (w/ followuptype):
  AND (sn.survey, sn.contact, sn.id) IN (
    select survey, contact, max(id) as latestsupportneedid
    from $dbschm.supportneed 
    where survey = sn.survey and contact = sn.contact
    and followuptype is not null
    group by survey, contact
  )
  -- supportneed (request) not followed up yet
  AND req.id NOT IN (select supportneed from $dbschm.followup)
  -- delay amount of time has passed
  AND req.updated < now() - make_interval(mins := (config.value#>>'{}')::int)
  -- request of supportneed (first one w/ requestid):
  AND (req.survey, req.contact, req.id) IN (
    select survey, contact, min(id) as supportneedrequestid
    from $dbschm.supportneed 
    where survey = sn.survey and contact = sn.contact
    group by survey, contact
  )
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $followups = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."followup start".PHP_EOL, count($followups));
  foreach ($followups as $followuprow) {
    $followuptype = $followuprow->{'followuptype'};
    $supportneedid = $followuprow->{'id'};
    $contactid = $followuprow->{'contact'};
    $survey = $followuprow->{'survey'};//for message table
    $destination = null;//phonenumber
    $category = $followuprow->{'category'};//replaceable
    print(date("Y-m-d H:i:s")." FOLLOWUP ".$followuptype." FOR ".$contactid.PHP_EOL);
    $sql = "
    select contact as contactdata
    , iv
    , optout
    from $dbschm.contact
    where id = :contact
    and coalesce(optout,'9999-9-9') > now()
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':contact', $contactid);
    $sth->execute();
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) == 0) {
      print(date("Y-m-d H:i:s")." FOLLOWUP ".$followuptype." FOR ".$contactid." STOPPED. Probably opt-out.".PHP_EOL);
    }
    foreach ($rows as $rownum => $row) {
      $iv = base64_decode($row['iv']);
      $cd = json_decode(decrypt($row['contactdata'],$iv));
      $destination = $cd->{'phonenumber'};
    }
    // actions
    require "followup.php"; // provides: $messagetemplate
    // followup is now in progress
  }
} // - "followup start"


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
    join $dbschm.supportneed sn on sn.survey = aus.survey
      and (aus.meta->'category'->sn.category)::boolean
    join $dbschm.contact on contact.id = sn.contact
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
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
    join $dbschm.supportneed sn on sn.contact = contact.id
      -- drop those belonging to support providers
      and sn.id not in (
        select supportneed.id
        from $dbschm.supportneed
        join $dbschm.annieusersurvey on annieusersurvey.survey = supportneed.survey
        where supportneed.contact = contact.id
        and (annieusersurvey.meta->'category'->supportneed.category)::boolean
      )
    cross join conf
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    -- no mail notification on supportneeds of supporttype INFORMATION
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
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
        $supportneedid = $r->{'supportneed'};
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
          mailOnReminder($contactdata,$supportneedid,$firstmessage,$lastmessage,array($annieuser),$mailcontent,$lang);
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
    join $dbschm.supportneed sn on sn.survey = aus.survey
      and (aus.meta->'category'->sn.category)::boolean
    join $dbschm.contact on contact.id = sn.contact
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
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
    join $dbschm.supportneed sn on sn.contact = contact.id
      -- drop those belonging to support providers
      and sn.id not in (
        select supportneed.id
        from $dbschm.supportneed
        join $dbschm.annieusersurvey on annieusersurvey.survey = supportneed.survey
        where supportneed.contact = contact.id
        and (annieusersurvey.meta->'category'->supportneed.category)::boolean
      )
    cross join conf
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    -- no mail notification on supportneeds of supporttype INFORMATION
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
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
        $supportneedid = $r->{'supportneed'};
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
          mailOnReminder($contactdata,$supportneedid,$firstmessage,$lastmessage,array($annieuser),$mailcontent,$lang);
        }
      }
    }
  }
} // - "provider & teacher reminder 2"

//: "provider & teacher sms reminder 1"
{
  $sql = "
  with conf as (
    select
      (smsfirstreminderdelay.value)::int delay
    , (smsfirstreminderdelay.value)::int/60/24 delaydays
    , (watchdoginterval.value)::int as interval
    , (watchdogstarttime.value#>>'{}')::text as starttime
    , (watchdogstarttime.value#>>'{}')::int/100 as starthour
    , (watchdogendtime.value#>>'{}')::text as endtime
    , (watchdogendtime.value#>>'{}')::int/100 as endhour
    from $dbschm.config watchdoginterval
    , $dbschm.config watchdogstarttime
    , $dbschm.config watchdogendtime
    , $dbschm.config smsfirstreminderdelay
    where 1=1
    and watchdoginterval.segment = 'watchdog' and watchdoginterval.field = 'interval'
    and watchdogstarttime.segment = 'watchdog' and watchdogstarttime.field = 'starttime'
    and watchdogendtime.segment = 'watchdog' and watchdogendtime.field = 'endtime'
    and smsfirstreminderdelay.segment = 'sms' and smsfirstreminderdelay.field = 'firstReminderDelay'
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
    join $dbschm.supportneed sn on sn.survey = aus.survey
      and (aus.meta->'category'->sn.category)::boolean
    join $dbschm.contact on contact.id = sn.contact
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
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
    join $dbschm.supportneed sn on sn.contact = contact.id
      -- drop those belonging to support providers
      and sn.id not in (
        select supportneed.id
        from $dbschm.supportneed
        join $dbschm.annieusersurvey on annieusersurvey.survey = supportneed.survey
        where supportneed.contact = contact.id
        and (annieusersurvey.meta->'category'->supportneed.category)::boolean
      )
    cross join conf
    where annieuser.notifications = 'IMMEDIATE'
    and coalesce(annieuser.validuntil,'9999-09-09') > now()
    and sn.status in ('NEW','OPENED')
    -- no sms/mail notification on supportneeds of supporttype INFORMATION
    and coalesce(sn.supporttype,'MISSING') != 'INFORMATION'
    -- latest supportneed row
    and sn.id = (
      select max(id) from $dbschm.supportneed
      where contact = sn.contact and survey = sn.survey
    )
    -- supportneed not in followup
    and sn.id NOT in (
        select supportneed.id
        from $dbschm.supportneed req
        join $dbschm.followup on followup.supportneed = req.id
        join $dbschm.supportneed on supportneed.contact = req.contact and supportneed.survey = req.survey
    )
  )

  select users.id, users.meta, users.iv
  , users.supportneed
  , users.survey
  , users.contact, contactdata, contactiv
  from users
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
  printf(date("Y-m-d H:i:s")."%4s  "."provider & teacher sms reminder 1".PHP_EOL, count($remindees));

  if (count($remindees)>=1) {

    require_once 'my_app_specific_library_dir/sms.php';

    $sql = "select value from $dbschm.config where segment='sms' and field='firstReminder'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $messagetemplate = isset($res->value) ? json_decode($res->value) : null;

    $sql = "select value from $dbschm.config where segment='sms' and field='validity'";
    $sth = $dbh->prepare($sql);
    $sth->execute();
    $res = $sth->fetch(PDO::FETCH_OBJ);
    $smsvalidity = isset($res->value) ? $res->value : 1440;//default 24h

    foreach ($remindees as $r) {
      $iv = base64_decode($r->{'iv'});
      $annieusermeta = json_decode(decrypt($r->{'meta'},$iv));
      if (isset($annieusermeta) && array_key_exists('phonenumber', $annieusermeta)) {
        $destination = $annieusermeta->{'phonenumber'};
        $contactiv = base64_decode($r->{'contactiv'});
        $contactdata = json_decode(decrypt($r->{'contactdata'},$contactiv));
        $replaceables = (object)array(
          "firstname" => isset($contactdata->firstname) ? $contactdata->{'firstname'} : null,
          "lastname" => isset($contactdata->lastname) ? $contactdata->{'lastname'} : null,
          "supportneedid" => $r->{'supportneed'}
        );
        // actions
        if (isset($destination) && isset($messagetemplate) && isset($lang)) {
          smsSend($destination,$messagetemplate,$replaceables,$lang,$smsvalidity);
        }
      }
    }
  }
} // - "provider & teacher sms reminder 1"

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

//: "stuck in survey reminder"
{
  $sql = "
  SELECT survey.id as survey
  , cs.contact
  , cs.status
  , co.iv
  , co.contact as contactdata
  , co.optout
  , stuck_reminder.value->>'message' as messagetemplate
  from $dbschm.survey
  join $dbschm.contactsurvey cs on cs.survey = survey.id
  join $dbschm.contact co on co.id = cs.contact
  join $dbschm.config stuck_reminder on stuck_reminder.segment = 'survey' and stuck_reminder.field = 'reminder'
  where survey.config is not null
  and survey.status = 'IN PROGRESS'
  and cs.status NOT IN ('1','2','100') --not started, reminded or finished (e.g. in between)
  -- last one for contact:
  and (cs.contact,cs.updated) in (
    select contact,max(updated)
    from $dbschm.contactsurvey
    where contact = cs.contact and survey = cs.survey
    group by contact
  )
  -- stuck reminded indicator:
  and not exists (
    select cs_stuck.contact, cs_stuck.survey
    from $dbschm.contactsurvey cs_stuck
    where cs_stuck.contact = cs.contact and cs_stuck.survey = cs.survey
    and cs_stuck.id != cs.id
    and cs_stuck.status = cs.status -- same status twice
    -- second to last one for contact (last but not same as last):
    and (cs_stuck.contact,cs_stuck.updated) in (
      select contact,max(updated) --last but...
      from $dbschm.contactsurvey
      where contact = cs.contact and survey = cs.survey
      and id != cs.id --...not the same as last (so second to last)
      group by contact
    )
  )
  -- has reminder delay passed last contactsurvey time (1 min extra for eliminating processing time)
  and cs.updated < now() - make_interval(mins := (stuck_reminder.value->>'delay')::int -1)
  and coalesce(co.optout,'9999-9-9') > now()
  ";
  //--=> stuck-reminder.php (does: send "reminder" to "contact")
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $remindees = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));
  printf(date("Y-m-d H:i:s")."%4s  "."stuck in survey reminder".PHP_EOL, count($remindees));
  $survey = null;
  $contact = null;
  $contactdata = null;
  $messagetemplate = null;

  // ...test if there is such info...
  // ...especially if there is not: nothing to do!
  if (count($remindees)>=1) {
    foreach ($remindees as $r) {
      print(date("Y-m-d H:i:s")." SEND STUCK REMINDER: ".$r->{'contact'}.PHP_EOL);
      $survey = $r->{'survey'};
      $contact = $r->{'contact'};
      $iv = base64_decode($r->{'iv'});
      $contactdata = json_decode(decrypt($r->{'contactdata'},$iv));
      $messagetemplate = $r->{'messagetemplate'};
      $contactsurveystatus = $r->{'status'};

      // actions
      require "stuck-reminder.php";
    }
  }
} // - "stuck in survey reminder"

//: "reminder"
{
  $sql = "
  select survey.id as survey
  , cs.contact
  , co.iv
  , co.contact as contactdata
  , co.optout
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
  and coalesce(co.optout,'9999-9-9') > now()
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
  , co.optout
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
  and coalesce(co.optout,'9999-9-9') > now()
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
      , optout
      from $dbschm.contact
      where id = :contact
      and coalesce(optout,'9999-9-9') > now()
      ";
      $sth = $dbh->prepare($sql);
      $sth->bindParam(':contact', $contact);
      $sth->execute();
      $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
      if (count($rows) == 0) {
        print(date("Y-m-d H:i:s")." INITIATE ".$survey." FOR ".$contact." STOPPED. Probably opt-out.".PHP_EOL);
      }
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
    and coalesce(validuntil,'9999-09-09') > now()
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
} // - "survey start"

?>