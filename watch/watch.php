<?php
/* watch.php
 * Copyright (c) 2019-2021 Annie Advisor
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

require_once('/opt/annie/settings.php');

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

require_once('/opt/annie/anniedb.php');
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

$sql = "select value from $dbschm.config where segment='watchdog' and field='interval'";
$sth = $dbh->prepare($sql);
$sth->execute();
$res = $sth->fetch(PDO::FETCH_OBJ);
$watchinterval = isset($res->value) ? json_decode($res->value) : null;

$sql = "select value from $dbschm.config where segment='mail' and field='dailyDigestSchedule'";
$sth = $dbh->prepare($sql);
$sth->execute();
$res = $sth->fetch(PDO::FETCH_OBJ);
$maildailydigestschedule = isset($res->value) ? json_decode($res->value) : null;

# Check acceptable time window
if (($runtime<$watchstart || $runtime>$watchend) && !$manualrun) {
  //print("Out of execution time window. Exit.".PHP_EOL);
  exit;
}
# Check matching interval
if (($runminute % $watchinterval) != 0 && !$manualrun) {
  //print("Not on our interval. Exit.".PHP_EOL);
  exit;
}

require_once('/opt/annie/mail.php');

$cipher = "aes-256-cbc";
function encrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
      $output = openssl_encrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}

function decrypt($string,$iv) {
  global $cipher, $salt;
  $output = false;
  if (in_array($cipher, openssl_get_cipher_methods())) {
      $output = openssl_decrypt($string, $cipher, $salt, $options=0, $iv);
  }
  return $output;
}

//
// BEGIN
//

//: "viesti ei mennyt perille"
if ("AD-246"=="DISABLED") {
  // AD-42: when there is already a supportneed for ongoing survey we want to add a comment to the supportneed only
  //TODO: lang
  $ivlen = openssl_cipher_iv_length($cipher);
  $iv = openssl_random_pseudo_bytes($ivlen);
  $enc_comment = encrypt("Viesti ei mennyt perille",$iv);
  $enc_iv = base64_encode($iv);//store $iv for decryption later
  $sql = "
  insert into $dbschm.supportneedcomment (updatedby,supportneed,body,iv)
  select 'Annie' as updatedby
  , supportneed.id as supportneed
  , :enc_comment
  , :enc_iv
  from $dbschm.message
  -- already acted on (the opposite of original selection, see below), plus we need the id
  join $dbschm.supportneed on supportneed.contact = message.contact and supportneed.survey = message.survey
  where message.status is not null and message.status = 'FAILED'
  and message.updated >= '2020-11-10' --sanity: first possible date (taken into use)
  -- not already acted on (via comment)
  and (message.contact,message.survey) not in (
    select snh.contact, snh.survey
    from $dbschm.supportneedcomment snc
    join $dbschm.supportneedhistory snh on snh.id = snc.supportneed
  )
  group by message.contact, message.survey, supportneed.id
  ";
  $sth = $dbh->prepare($sql);
  $sth->bindParam(':enc_comment', $enc_comment);
  $sth->bindParam(':enc_iv', $enc_iv);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."viesti ei mennyt perille (kommentti)".PHP_EOL, $sth->rowCount());

  /*
  - Jos viesti ei mene perille (message-tauluun kirjoitetaan jotain muuta kuin DELIVERED), luodaan tukitarve "viesti ei mennyt perille".
  */
  $sql = "
  insert into $dbschm.supportneed (updatedby,contact,category,status,survey)
  select 'Annie' as updatedby
  , contact
  , 'W' as category --parameterize?
  , '1' as status
  , survey
  from $dbschm.message
  where message.status is not null and message.status = 'FAILED'
  and updated >= '2020-11-10' --sanity: first possible date (taken into use)
  -- not already acted on (AD-42: at all)
  and (contact,survey) not in (
    select contact,survey
    from $dbschm.supportneedhistory
  )
  group by contact, survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."message not delivered (supportneed)".PHP_EOL, $sth->rowCount());

  $sql = "
  insert into $dbschm.supportneedhistory
  select *
  from $dbschm.supportneed
  where category = 'W'
  and updated > now() - interval '1 minutes' --sanity: ~recent
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();

  $sql = "
  insert into $dbschm.contactsurvey (updatedby,contact,survey,status)
  select 'Annie' as updatedby
  , contact
  , survey
  , '100' as status
  from $dbschm.supportneed
  where category = 'W'
  and updated > now() - interval '1 minutes' --sanity: ~recent
  and not exists (
    select cs.contact
    from $dbschm.contactsurvey cs
    where cs.status = '1' --started, not reminded
    -- last one for contact:
    and (cs.contact,cs.updated) in (
      select contact,max(updated)
      from $dbschm.contactsurvey
      group by contact
    )
  )
  group by contact, survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
} // - "viesti ei mennyt perille"

//: "opiskelija ei vastannut"
if ('AD-246'=='DISABLED') {
  /*
  - Jos opiskelija ei vastaa muistutusviestiin surveyn endtimeen mennessä niin luodaan supportneed "Opiskelija ei vastannut" kun kyselykierros suljetaan.
  */
  $sql = "
  insert into $dbschm.supportneed (updatedby,contact,category,status,survey)
  select 'Annie' as updatedby
  , message.contact
  , 'X' as category
  , '1' as status
  , message.survey
  from $dbschm.message
  join $dbschm.survey on survey.id = message.survey
  where now() > survey.endtime
  and survey.status = 'IN PROGRESS'
  -- what defines reminder message? - contactsurvey.status
  and (message.contact,message.survey) in (--fixme
    select cs.contact,cs.survey
    from $dbschm.contactsurvey cs
    where cs.survey = message.survey
    and cs.contact = message.contact
    and cs.status = '2' --reminded, for optimized situation
    -- last one for contact:
    and (cs.contact,cs.updated) in (
      select contact,max(updated)
      from $dbschm.contactsurvey
      group by contact
    )
  )
  -- not already acted on
  and (message.contact,message.survey) not in (
    select contact,survey
    from $dbschm.supportneed
    where category = 'X'
  )
  group by message.contact, message.survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."no response from contact".PHP_EOL, $sth->rowCount());

  $sql = "
  insert into $dbschm.supportneedhistory
  select *
  from $dbschm.supportneed
  where category = 'X'
  and updated > now() - interval '1 minutes' --sanity: ~recent
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();

  $sql = "
  insert into $dbschm.contactsurvey (updatedby,contact,survey,status)
  select 'Annie' as updatedby
  , contact
  , survey
  , '100' as status
  from $dbschm.supportneed
  where category = 'X'
  and updated > now() - interval '1 minutes' --sanity: ~recent
  and (contact,'100') not in (
    select cs.contact,cs.status
    from $dbschm.contactsurvey cs
    where cs.survey = supportneed.survey
    -- last one for contact:
    and (cs.contact,cs.survey,cs.updated) in (
      select contact,survey,max(updated)
      from $dbschm.contactsurvey
      group by contact,survey
    )
  )
  group by contact, survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
} // - "opiskelija ei vastannut"

//: "ensimmäisen vastauksen supportneed"
{
  /*
  - Jos opiskelija on vastannut ensimmäiseen viestiin, mutta ei jatkokyselyyn, niin luodaan ensimmäisen vastauksen (esim. B tai C) mukainen supportneed kun kyselykierros suljetaan

  nb! timeline may be exceptionally challenging here. if we can avoid the problem, great
  */
  $sql = "
  insert into $dbschm.supportneed (updatedby,contact,category,status,survey)
  with recursive branches as (
    select su.id as survey, j.key, null as parent, j.value as config, 0 as level
    from $dbschm.survey su
    cross join jsonb_each(su.config) j
    where j.key like 'branch%'
    union all
    select br.survey, j.key, br.key as parent, j.value as config, level+1
    from branches br
    cross join jsonb_each(br.config) j
    where j.key like 'branch%'
  )
  select 'Annie' as updatedby
  , cs.contact
  , coalesce((
      select distinct config->>'category'
      from branches br
      where br.survey = cs.survey
      and br.key = 'branch'||cs.status --as matchme
    ),'Z') as category
  , '1' as status
  , cs.survey
  from $dbschm.contactsurvey cs
  join $dbschm.survey on survey.id=cs.survey
  where now() > survey.endtime
  and survey.status = 'IN PROGRESS'
  and (cs.contact,cs.survey) in (
    select contact,survey
    from $dbschm.message
    where status != 'FAILED' --NOT failed
  )
  -- last one
  and (cs.contact,cs.survey,cs.updated) in (
    select contact,survey,max(updated)
    from $dbschm.contactsurvey
    group by contact,survey
  )
  -- config: current phase has children
  and (cs.survey,cs.status) in (
    select br.survey, replace(br.key,'branch','') as matchme
    from branches br
    cross join jsonb_each(br.config) j
    where br.config is not null
    and j.key like 'branch%' --given response so far that... has children that shouldve been
  )
  -- supportneed does not exist
  and (cs.contact,cs.survey,cs.status) not in (
    select sn.contact,sn.survey,cs.status --nb! supportneed.category != contactsurvey.status
    from $dbschm.supportneed sn
    join branches br on br.survey = sn.survey
    and br.key = 'branch'||cs.status --as matchme
    -- limit more?
  )
  group by cs.contact, cs.status, cs.survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."supportneed based on first answer".PHP_EOL, $sth->rowCount());

  $sql = "
  insert into $dbschm.supportneedhistory
  select *
  from $dbschm.supportneed
  where 1=1
  --limit somehow? and category = ?
  and updated > now() - interval '1 minutes' --sanity: ~recent
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();

  $sql = "
  insert into $dbschm.contactsurvey (updatedby,contact,survey,status)
  select 'Annie' as updatedby
  , contact
  , survey
  , '100' as status
  from $dbschm.supportneed
  where 1=1
  --limit somehow? and category = ?
  and updated > now() - interval '1 minutes' --sanity: ~recent
  and (contact,survey,'100') not in (
    select cs.contact,cs.survey,cs.status
    from $dbschm.contactsurvey cs
    where cs.survey = supportneed.survey
    -- last one for contact:
    and (cs.contact,cs.survey,cs.updated) in (
      select contact,survey,max(updated)
      from $dbschm.contactsurvey
      group by contact,survey
    )
  )
  group by contact, survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
} // - "ensimmäisen vastauksen supportneed"

//: "close student initiated"
{
  /*
  Closing unfinished studentInitiated cases
  Instead of using survey endtime let’s use last message + lastMessageDelay
  */

  // create supportneeds if doesnt exist
  $sql = "
  insert into $dbschm.supportneed (updatedby,contact,survey,category,status)
  with recursive branches as (
    select su.id as survey, j.key, null as parent, j.value as config, 0 as level
    from $dbschm.survey su
    cross join jsonb_each(su.config) j
    where j.key like 'branch%'
    union all
    select br.survey, j.key, br.key as parent, j.value as config, level+1
    from branches br
    cross join jsonb_each(br.config) j
    where j.key like 'branch%'
  )
  select 'Annie' as updatedby
  , cs.contact
  , cs.survey --Y
  , coalesce((
      select distinct config->>'category'
      from branches br
      where br.survey = cs.survey
      and br.key = 'branch'||cs.status --as matchme
    ),'Z') as category
  , '1' as status
  from $dbschm.contactsurvey cs
  join $dbschm.message msg on msg.contact=cs.contact and msg.survey=cs.survey
  join $dbschm.config ON config.segment='survey' and config.field='lastMessageDelay'
  where cs.survey = 'Y'
  and greatest(cs.updated,msg.updated) < current_timestamp - make_interval(hours := cast(config.value as int))
  -- does not exist (status=100)... which is last one (see below):
  and (cs.contact,cs.survey,cs.status) not in (
    select contact,survey,status
    from $dbschm.contactsurvey
    where status = '100' --finished
  )
  -- last one for contact+survey:
  and (cs.contact,cs.survey,cs.updated) in (
    select contact,survey,max(updated)
    from $dbschm.contactsurvey
    group by contact,survey
  )
  -- supportneed does not exist
  and (cs.contact,cs.survey) not in (
    select sn.contact,sn.survey
    from $dbschm.supportneed sn
    where sn.survey = 'Y'
    -- limit more?
  )
  group by cs.contact, cs.survey, cs.status
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();

  $sql = "
  insert into $dbschm.supportneedhistory
  select *
  from $dbschm.supportneed
  where survey = 'Y'
  --limit somehow?
  and updated > now() - interval '1 minutes' --sanity: ~recent
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();

  // end contactsurveys
  $sql = "
  insert into $dbschm.contactsurvey (updatedby,contact,survey,status)
  select 'Annie' as updatedby
  , cs.contact
  , cs.survey
  , '100' as status
  from $dbschm.contactsurvey cs
  join $dbschm.message msg on msg.contact=cs.contact and msg.survey=cs.survey
  join $dbschm.config ON config.segment='survey' and config.field='lastMessageDelay'
  where cs.survey = 'Y'
  and greatest(cs.updated,msg.updated) < current_timestamp - make_interval(hours := cast(config.value as int))
  -- does not exist (status=100)... which is last one (see below):
  and (cs.contact,cs.survey,cs.status) not in (
    select contact,survey,status
    from $dbschm.contactsurvey
    where status = '100' --finished
  )
  -- last one for contact+survey:
  and (cs.contact,cs.survey,cs.updated) in (
    select contact,survey,max(updated)
    from $dbschm.contactsurvey
    group by contact,survey
  )
  group by cs.contact, cs.survey
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  printf(date("Y-m-d H:i:s")."%4s  "."close student initiated".PHP_EOL, $sth->rowCount());
} // - "close student initiated"

//: "kyselykierroksen päättäminen"
{
  /*
  milloin endtime tulee, ja tällöin merkitsee statukseen FINISHED
  */
  // ensin päätetään mahdollisesti keskeneräiset contactsurvey ko surveyhin
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
    select id as annieuser
    from $dbschm.annieuser
    where (superuser = true or id in (
      select annieuser
      from $dbschm.annieusersurvey
      where survey = :survey
    ))
    and coalesce(notifications,'DISABLED') != 'DISABLED'
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $surveyrow->{'survey'});
    $sth->execute();
    $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));

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
} // - "kyselykierroksen päättäminen"

//: "muistutus"
{
  /*
  Ongelma tai tarve:
    Jos opiskelija ei vastaa tietyn ajan kuluessa, lähetetään muistusviesti vastaamisesta. Tällä saadaan nostettua vastausprosenttia.
  Ehdotettu ratkaisu:
    survey-taulun config-jsoniin
    - "reminder" joka on muistutusviestin sisältö ja
    - "reminderDelay" jossa numeerinen arvo, joka kertoo kuinka monen tunnin kuluttua muistutus lähetetään jos vastausta ei ole saatu
  */
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
} // - "muistutus"

//: "monimuistutus"
{
  /*
  Ks. "muistutus"
  Tässä lisätään muistutuskertoja
  */
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
  and cs.status = '1' --reminded (at least once) --AD-186: 1->2
  -- last one for contact:
  and (cs.contact,cs.updated) in (
    select contact,max(updated)
    from $dbschm.contactsurvey
    where contact = cs.contact
    and status = cs.status --AD-186 added
    group by contact
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
      and c.survey = cs.survey and c.status = '2' --reminded
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
} // - "monimuistutus"

//: "kyselykierroksen aloitus"
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
    select id as annieuser
    from $dbschm.annieuser
    where (superuser = true or id in (
      select annieuser
      from $dbschm.annieusersurvey
      where survey = :survey
    ))
    and coalesce(notifications,'DISABLED') != 'DISABLED'
    ";
    $sth = $dbh->prepare($sql);
    $sth->bindParam(':survey', $survey);
    $sth->execute();
    $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));

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
// - "kyselykierroksen aloitus"

//: "daily digest"
if ($runtime==$maildailydigestschedule)
{
  // mail to "annieuser is responsible for"
  // nb! not for superusers
  $sql = "
  select au.id as annieuser
  , count(distinct sn.contact) as supportneedcount
  , count(distinct msg.contact) as messagecount
  from $dbschm.annieuser au
  join $dbschm.annieusersurvey aus on aus.annieuser = au.id
  left join $dbschm.supportneed sn on sn.survey = aus.survey
    and (aus.meta->'category'->sn.category)::boolean
    and sn.updated >= now() - interval '1 days'
  left join $dbschm.message msg on msg.survey = aus.survey
    and msg.contact = sn.contact
    and msg.updated >= now() - interval '1 days'
  where au.notifications = 'DAILYDIGEST'
  and (sn.contact is not null or msg.contact is not null)
  group by au.id
  ";
  $sth = $dbh->prepare($sql);
  $sth->execute();
  $annieusers = json_decode(json_encode($sth->fetchAll(PDO::FETCH_ASSOC)));

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
