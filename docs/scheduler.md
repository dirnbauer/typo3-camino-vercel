# Scheduler And Cron

## Important Vercel Limitation

Vercel containers do not run a traditional long-lived Linux cron daemon for this
project. Do not expect `crontab`, `systemd timers`, or background daemons inside
the container to be reliable.

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

## Enable Vercel Cron

Add this to `vercel.json` when you are ready to enable the scheduler:

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

On Vercel Hobby, cron jobs are limited to once per day and can run within the
selected hour rather than exactly at the selected minute. Use Pro if TYPO3 tasks
must run more often or with tighter timing.

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
- Depend on exact minute execution on Hobby.
- Leave the cron endpoint without `CRON_SECRET`.
- Use frontend page requests to trigger maintenance tasks.

## Optional Keepalive

The project also includes a lightweight endpoint:

```text
/_vercel_keepalive.php
```

It does not run TYPO3 Scheduler and does not touch the database. It only keeps
the PHP/Apache container path warm when called by Vercel Cron on Pro or by an
external uptime scheduler.

Do not add a frequent keepalive cron to the public template. Vercel Hobby cron
jobs can run only once per day, so `*/5 * * * *` would fail for free deploys.

## Sources

- Vercel Cron usage/pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- Vercel Cron security and idempotency: https://vercel.com/docs/cron-jobs/manage-cron-jobs
- TYPO3 Scheduler docs: https://docs.typo3.org/c/typo3/cms-scheduler/main/en-us/
