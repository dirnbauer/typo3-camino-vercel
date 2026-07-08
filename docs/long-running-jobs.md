# Long-Running Jobs

## Short Answer

Do not run multi-hour TYPO3 jobs as one Vercel request.

Vercel can trigger TYPO3 Scheduler through an HTTP endpoint, but each trigger is
still a bounded function/container invocation. That works for small scheduled
tasks and small Solr batches. It is not the right execution model for indexing a
large website for several hours.

## Current Vercel Runtime Numbers

These are platform limits to design around:

| Area | Hobby | Pro / Enterprise | Impact For TYPO3 |
| --- | ---: | ---: | --- |
| Function/container invocation duration | 300s max | 300s default, 800s max | One Scheduler HTTP run must finish before this. |
| Extended max duration | Not available | 1800s beta for selected Node.js/Python runtimes | Do not rely on this for the PHP Apache container. |
| Cron frequency | once per day | once per minute | Hobby is not useful for frequent indexing queues. |
| Cron precision | within the selected hour | per minute | Hobby is not exact enough for production queues. |
| Writable runtime filesystem | `/tmp` scratch only | `/tmp` scratch only | Job progress must live in DB/Solr/object storage, not local files. |
| Runtime log retention | 1 hour | 1 day on Pro, 3 days on Enterprise | Use log drains/monitoring for production jobs. |

Build time is a different limit. Vercel's build step can run longer than a
normal request and currently stops at 45 minutes, but build-time is the wrong
place for production Solr indexing: the build should not mutate the production
database or Solr index.

## What This Means For Solr Indexing

EXT:solr uses an index queue. Editor changes are queued in TYPO3, then the
Index Queue Worker scheduler task sends queued items to Solr. This is good for
serverless-style execution because the work can be split.

Bad pattern:

```text
Run one Scheduler request that indexes the whole site for 2-3 hours.
```

Good pattern:

```text
Queue the site once.
Run many short Index Queue Worker batches.
Persist progress in the TYPO3 database and Solr index.
Stop when the queue is empty.
```

For this Vercel starter, set the Index Queue Worker task's
"Number of documents to Index" to a realistic batch size. Start conservatively:

| Site Size | First Batch Size To Try | Expected Use |
| --- | ---: | --- |
| Small demo site | 25-100 documents | Usually fine through Vercel Cron or manual Scheduler runs. |
| Normal content site | 25-100 documents | Tune by measuring one run; keep plenty of timeout margin. |
| Large site / many languages | 10-50 documents | Prefer an external worker so retries and runtime are controlled. |
| File indexing / Tika | 1-10 files | Use an external worker; PDFs and office documents are slow and memory-heavy. |

The right number depends on page rendering cost, TypoScript, uncached plugins,
Solr latency, language count, file extraction, and database latency. A batch
that takes 30 seconds locally can be much slower after a cold start or when Solr
is remote.

## Recommended Architecture

### Option A: External Worker For Production

Use this for large initial indexes, reindexes, file indexing, and anything that
can take hours.

The worker can be:

- an always-on VM/container near the database and Solr endpoint
- a TYPO3 host or agency worker box
- a CI runner with an explicit timeout and access to production secrets
- a managed job platform

The worker needs the same code version and the production environment variables:

```dotenv
DATABASE_URL=<production-database>
TYPO3_SOLR_ENABLED=1
TYPO3_SOLR_URL=<production-solr-core>
TYPO3_CONTEXT=Production/Worker
```

Then run Scheduler from CLI:

```bash
vendor/bin/typo3 scheduler:list
vendor/bin/typo3 scheduler:execute <index-queue-worker-task-id> --no-interaction
```

Run the task in a loop until the queue is empty. Keep the worker close to the
database and Solr. Do not run several workers against the same queue unless you
have verified locking and non-overlap behavior.

### Option B: Chunked Vercel Cron

Use this for demos and smaller production queues.

The included endpoint is:

```text
/api/cron/typo3-scheduler.php
```

It runs:

```bash
vendor/bin/typo3 scheduler:run --no-interaction
```

On Pro/Enterprise, a once-per-minute cron can process small queue chunks over
time:

```json
{
  "crons": [
    {
      "path": "/api/cron/typo3-scheduler.php",
      "schedule": "* * * * *"
    }
  ]
}
```

Only use this when each Scheduler run is comfortably below the invocation limit.
If one run can exceed the next cron interval, reduce the Solr batch size or move
the job to an external worker. Overlapping Scheduler runs are a real operational
risk.

### Option C: Manual One-Time Indexing

For a controlled initial demo, you can run batches manually from a machine that
has production database and Solr access:

```bash
vendor/bin/typo3 scheduler:execute <index-queue-worker-task-id> --no-interaction
```

This is useful for a first index before a presentation. It is not a good
long-term production operations model unless the command is wrapped with
logging, locking, retry, and alerting.

## Do / Don't

Do:

- Use EXT:solr Index Queue Worker batches.
- Keep each batch short and repeatable.
- Measure one batch before choosing a cron interval.
- Put job progress in the TYPO3 database/Solr, not local files.
- Use an external worker for hour-scale jobs.
- Keep Vercel, database, Solr, and Blob/S3 in nearby regions.
- Add log drain/monitoring for production jobs because Vercel runtime log
  retention is limited.

Do not:

- Run a full-site Solr reindex as one HTTP request.
- Rely on a Linux cron daemon inside the Vercel container.
- Use Vercel build time to mutate the production Solr index.
- Run Tika/file indexing through frequent Vercel requests.
- Set Solr batch size so high that a cold start pushes the run near timeout.
- Assume Hobby Cron can maintain a production indexing queue.

## Decision Rule

Use Vercel Cron only when the job can be split and each invocation finishes in
well under the platform limit. If the honest estimate is "this may run for
hours", use an external worker.

For TYPO3 Solr:

```text
Small content updates: Vercel Cron is acceptable.
Initial full index of a small demo: Vercel Cron or manual batches can work.
Initial full index of a real site: external worker recommended.
File indexing/Tika: external worker strongly recommended.
Large forced reindex: external worker required.
```

## Sources

- Vercel Functions limits: https://vercel.com/docs/functions/limitations
- Vercel duration configuration: https://vercel.com/docs/functions/configuring-functions/duration
- Vercel Cron usage and pricing: https://vercel.com/docs/cron-jobs/usage-and-pricing
- Vercel Cron management: https://vercel.com/docs/cron-jobs/manage-cron-jobs
- Vercel runtimes and container images: https://vercel.com/docs/functions/runtimes
- Vercel platform limits: https://vercel.com/docs/limits
- EXT:solr Scheduler tasks: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Backend/Scheduler.html
- EXT:solr first indexing: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/GettingStarted/IndexTheFirstTime.html
