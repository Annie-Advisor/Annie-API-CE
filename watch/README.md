# Annie. Watch

## Description

Watchdog is a PHP command line script initially designed to be called via cron but the execution can be started from wherever, for example Jenkins.

Job description in jenkins gives context:

> Run watchdog for Annie every _watchdog.interval_ minutes (15 minutes usually, 5 minutes for dev) for all clients concurrently between _watchdog.starttime_ ("0800") and _watchdog.endtime_ ("2000") client host local time.
> Daily digest is timed separately via _mail.dailyDigestSchedule_ ("0800") but must match watchdog time and interval.
> 
> NB! Watchdog is located at host (version handled elsewhere) but path and execution (args etc) are assumed to be same for all.

where, for example, _watchdog.interval_ refers to database table `config` where column `segment` has value _'watchdog'_ and column `field` has value _'interval'_.

## Tasks

Watchdog has tasks:

- `provider & teacher reminder 1`
- `provider & teacher reminder 2`
- `survey end`
- `reminder`
- `n-reminder`
- `survey start`
- `daily digest`

The order of executing tasks is purposeful.

Details about the tasks should be added (to-do) or looked from scripts directly at this time.
