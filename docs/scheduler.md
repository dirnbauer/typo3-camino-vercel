# Scheduler And Cron

## Important Vercel Limitation

Vercel containers do not run a traditional long-lived Linux cron daemon for this
project. Do not expect `crontab`, `systemd timers`, or background daemons inside
the container to be reliable.

Cron-triggered requests are still Vercel function/container invocations. Design
for the current duration limits:

- Hobby: 300 seconds maximum.
- Pro/Enterprise: 300 seconds default, configurable up to 800 seconds for
  normal function workloads.
- Extended durations can be beta and documented for selected runtimes; do not
  assume a runtime-specific beta duration applies to this PHP
  Container Image path.

Use one of these instead:

- Vercel Cron Jobs
- an external uptime/cron service that calls an HTTPS endpoint
- a separate worker platform for heavier jobs

## What Is Included

This project installs:

```text
typo3/cms-scheduler
```

EXT:solr indexing tasks also depend on TYPO3 Scheduler. The extension can be
installed in this project, but Vercel still does not provide a Linux cron daemon
inside the container. Use the protected HTTP cron endpoint below or an external
cron caller for indexing queues.

For Solr indexing, do not configure one scheduler run to process the whole site
if that can take many minutes or hours. Use the EXT:solr Index Queue Worker task
with a bounded "Number of documents to Index" per run. Start with 25-100
documents for normal pages and reduce the batch size for expensive pages,
multi-language sites, or file/Tika indexing. Keep one run comfortably below the
runtime limit, preferably below 60-120 seconds.

It also includes a protected HTTP endpoint:

```text
/api/cron/typo3-scheduler.php
```

The endpoint runs:

```bash
vendor/bin/typo3 scheduler:run --no-interaction
```

## Security

The endpoint refuses to run unless `CRON_SECRET` is configured and the request
uses:

```http
Authorization: Bearer <CRON_SECRET>
```

Generate the secret:

```bash
openssl rand -base64 32
```

Add it to Vercel:

```bash
vercel env add CRON_SECRET production
```

## Vercel Cron In This Repo

`vercel.json` includes a safe daily Scheduler cron by default:

```json
{
  "crons": [
    {
      "path": "/api/cron/typo3-scheduler.php",
      "schedule": "0 3 * * *"
    }
  ]
}
```

This is intentionally daily so free/Hobby clones still deploy. On Vercel Hobby,
cron jobs are limited to once per day and can run within the selected hour
rather than exactly at the selected minute. A more frequent cron expression in
the public template would make Hobby deployments fail.

`vercel.pro.json` is the supported Pro profile. It adds a three-minute warm-up
and runs Scheduler every 15 minutes:

```json
{
  "crons": [
    {
      "path": "/api/cron/typo3-warmup.php",
      "schedule": "*/3 * * * *"
    },
    {
      "path": "/api/cron/typo3-scheduler.php",
      "schedule": "*/15 * * * *"
    }
  ]
}
```

Once-per-minute cron can process small queue chunks over time, but it must not
overlap with a still-running previous Scheduler call. If the next cron tick may
arrive before the current run finishes, reduce the Solr batch size or move the
job to an external worker.

Vercel sends `Authorization: Bearer <CRON_SECRET>` automatically to cron jobs
when the `CRON_SECRET` environment variable exists. The endpoint rejects cron
requests without that header.

For multi-hour jobs, see [long-running jobs](long-running-jobs.md).

## Test Manually

```bash
curl -i \
  -H "Authorization: Bearer $CRON_SECRET" \
  https://<your-project>.vercel.app/api/cron/typo3-scheduler.php
```

## Scheduler Do/Don't

Do:

- Keep scheduled tasks idempotent.
- Keep tasks short.
- Use database locks for tasks that must not overlap.
- Check Vercel runtime logs after enabling cron.

Do not:

- Run heavy imports through Vercel Cron.
- Run a full-site Solr reindex as one Vercel request.
- Depend on exact minute execution on Hobby.
- Leave the cron endpoint without `CRON_SECRET`.
- Use frontend page requests to trigger maintenance tasks.

## Pro Warm-Up

The protected `/api/cron/typo3-warmup.php` endpoint checks database and Redis,
performs local loopback requests to `/` and `/typo3/`, and pings Solr. This
warms the real TYPO3 frontend/backend code paths before Vercel's documented
five-minute production idle scale-down window.

Deploy it with:

```bash
vercel deploy --prod -A vercel.pro.json
```

Do not copy this schedule into `vercel.json`. Hobby permits cron only once per
day, so the public one-click profile must remain daily.

## Sources

- Vercel Cron usage/pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- Vercel Cron security and idempotency: https://vercel.com/docs/cron-jobs/manage-cron-jobs
- TYPO3 Scheduler docs: https://docs.typo3.org/c/typo3/cms-scheduler/main/en-us/
