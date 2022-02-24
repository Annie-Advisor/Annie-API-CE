# Annie. Watch

## Description

Watchdog is a PHP command line script initially designed to be called via cron but the execution can be started from wherever, for example Jenkins. The design is currently such that with 5 minute precision the execution times can be tuned via database table `config` and its rows with columns `segment.field` matching and column `value` data as the chosen config value. E.g. `watchdog.interval` means basically SQL query:
  ```
  SELECT value
  FROM config
  WHERE segment='watchdog'
  AND field='interval'
  ```

Job description in jenkins gives context:

> Run watchdog for Annie every _watchdog.interval_ minutes (15 minutes usually, 5 minutes for dev) for all clients concurrently between _watchdog.starttime_ ("0800") and _watchdog.endtime_ ("2000") client host local time.
> Daily digest is timed separately via _mail.dailyDigestSchedule_ ("0800") but must match watchdog time and interval.
> 
> NB! Watchdog is located at host (version handled elsewhere) but path and execution (args etc) are assumed to be same for all.

## Tasks

Watchdog has tasks:

- `close student initiated`
- `kyselykierroksen päättäminen` or "end survey"
- `muistutus` or "reminder"
- `monimuistutus` or "n-reminder"
- `kyselykierroksen aloitus` or "initiate survey"
- `daily digest`

The order of executing tasks is purposeful.

Details about the tasks should be added (to-do) or looked from scripts directly at this time.
