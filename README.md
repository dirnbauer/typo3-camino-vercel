# TYPO3 Camino on Vercel

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&env=TYPO3_SETUP_ADMIN_USERNAME%2CTYPO3_SETUP_ADMIN_PASSWORD%2CTYPO3_ENCRYPTION_KEY&envDefaults=%7B%22TYPO3_SETUP_ADMIN_USERNAME%22%3A%22admin%22%7D&envDescription=Choose+a+backend+username%2C+set+a+strong+random+backend+password%2C+and+paste+a+stable+96-character+hex+TYPO3+encryption+key.+The+Deploy+Button+creates+a+public+Vercel+Blob+store+for+durable+uploaded+files.+Add+a+real+database+later+for+stable+backend+login+and+durable+content.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md&stores=%5B%7B%22type%22%3A%22blob%22%2C%22access%22%3A%22public%22%7D%5D)

This is **not an official TYPO3 package**. It is a community starter that uses
the official TYPO3 Camino distribution and packages TYPO3 14.3 as a PHP 8.4
FPM/nginx container on Alpine Linux for Vercel Container Images.

Live demo: [typo3-camino-vercel.vercel.app](https://typo3-camino-vercel.vercel.app)

## Choose One Of Two Setups

| | One-click test | Professional hosting |
|---|---|---|
| Best for | Fast evaluation with fewer features | Durable editorial and larger read-heavy sites |
| Setup | Deploy Button, no database | Pro/Enterprise plus SQL, object storage, and operations |
| State | Temporary SQLite; edits can disappear | Durable PostgreSQL/MySQL and Blob/S3 |
| Speed strategy | Automatic CDN cache for public demo pages | Three-minute warmer, optional CDN, regional services |
| Search/jobs | No Solr and no cron | Managed Solr and bounded Scheduler/worker jobs |

Start with the one-click test when you only want to see TYPO3. Choose the
professional setup before any editor creates content that matters. See
[Choose one of two setups](docs/deployment-profiles.md) for the complete
decision guide and the honest large-site boundary.

## Solution 1: Install In One Click

This path is suitable for a disposable test and does not require a database.

1. Click **Deploy with Vercel**.
2. Sign in to Vercel. A Hobby account is enough for the initial test.
3. Keep the public Vercel Blob store selected. It makes uploaded files durable.
4. Enter these three values:

```dotenv
TYPO3_SETUP_ADMIN_USERNAME=admin
TYPO3_SETUP_ADMIN_PASSWORD=<your-own-long-random-password>
TYPO3_ENCRYPTION_KEY=<96-random-hex-characters>
```

Generate the encryption key locally:

```bash
openssl rand -hex 48
```

5. Click **Deploy** and wait until the deployment state is **Ready**. A Git
   push is not immediately online because Vercel must build and activate the
   container images first.
6. Open the generated site URL. The backend is at `/typo3/`.

There is no shared default password. The username and password are exactly the
values entered during deployment. Store both the password and encryption key in
a password manager; never commit them to Git.

## Important Durability Warning

> **The one-click SQLite database is temporary. It is only a smoke test. Page
> changes, records, backend sessions, and extension state can disappear or differ
> between Vercel instances until a real database is connected.**

The Blob store fixes files, not database data. Use this matrix when deciding
what the deployment can safely do:

| Concern | One-click Hobby test | Durable setup |
|---|---|---|
| Frontend demo | Yes | Yes |
| Backend login | Short tests only | Stable with SQL database |
| Pages and records | Temporary SQLite | Durable SQL database |
| Uploaded files | Durable when Blob was accepted | Vercel Blob or S3/R2 |
| Generated image derivatives | Durable through the FAL storage | Vercel Blob or S3/R2 |
| Scheduler | None in the one-click profile | Frequent Vercel Cron on Pro |
| Solr | Not deployed | External managed Solr 10 |
| Cold-start mitigation | Automatic CDN cache for eligible public pages | Three-minute Pro warm-up plus optional CDN cache |

A practical durable free demo can still cost zero while every provider remains
inside its free allowance:

- Vercel Hobby for personal, non-commercial testing
- a free PostgreSQL database such as Neon or Supabase, or a free
  MySQL-compatible TiDB Cloud database
- Vercel Blob within its Hobby allowance, or Cloudflare R2

It is free only while every service remains inside its current free-tier limits.
The database is an additional setup step, so a fully durable demo is not yet a
literal one-click deployment.

The one-click profile deploys only the TYPO3 container and automatically gives
eligible anonymous SQLite demo pages a five-minute Vercel CDN cache policy.
TYPO3 itself first confirms that the page is cacheable; responses marked
private, personalized, or non-cacheable are rejected by the Vercel middleware.
This keeps repeat frontend views away from the cold PHP origin without sharing
editor or visitor state. The first uncached page and `/typo3/` can still
experience a container cold start.

## Solution 2: Professional Hosting

Use Vercel Pro or Enterprise, a durable database near the compute region,
Vercel Blob or S3/R2, and managed external Solr when search is required. Add
Redis only when shared cache behavior is useful. Deploy the Pro schedule with
`scripts/deploy-pro.sh`; it warms frontend/backend every three minutes and runs
TYPO3 Scheduler every 15 minutes. This is a best-effort latency mitigation; it
cannot reserve either the TYPO3 or Solr container.

This can serve larger read-heavy sites when anonymous pages are cached at the
edge and stateful services are external, but capacity must be load-tested with
the real site. Vercel still cannot guarantee a permanently warm TYPO3 instance.
Use an always-on TYPO3 origin with Vercel as CDN/delivery when predictable
first-hit latency is mandatory. Follow [Professional hosting](docs/deployment-profiles.md)
and [Production hardening](docs/production-hardening.md).

## Add A Real Database

Create a database near the Vercel compute region and add its pooled connection
URL as a Production environment variable:

```dotenv
DATABASE_URL=postgresql://user:password@host/database?sslmode=require
```

MySQL and MySQL-compatible services also work:

```dotenv
DATABASE_URL=mysql://user:password@host:3306/database?ssl-mode=REQUIRED
```

For a new empty database, temporarily set:

```dotenv
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

Deploy once, verify the frontend and backend, then set both values back to `0`
and deploy again. This removes avoidable database checks and writes from every
new container start. See [Database setup](docs/database.md) for provider details.

## Durable Files

The preferred all-Vercel path is the included TYPO3 FAL driver named
`vercel_blob`. New Blob connections use Vercel OIDC where available and fall
back to `BLOB_READ_WRITE_TOKEN` for older connections and CLI work. The normal
Deploy Button flow configures the store automatically.

The repository also keeps a separate `vercel_s3` FAL driver for Cloudflare R2,
AWS S3, MinIO, and other S3-compatible storage.

The Camino files and their responsive demo derivatives are baked into the
image for deterministic first-page rendering. New editor uploads and their
derivatives use Blob/S3; they are not written to the image filesystem.

Vercel Functions currently impose a 4.5 MB request-body limit, so the normal
TYPO3 uploader is configured to 4 MB. For larger files, use **Media > Large
upload** or **Large upload to Vercel Blob** in the Files module. It
automatically switches from the bundled Camino files to a writable Blob folder
and shows the destination. The included flow checks TYPO3 permissions, then
uploads directly from the browser to Blob with a short-lived
path/size/type-scoped token. The default limit is 5 GiB and can be configured
up to Vercel Blob's 5 TB limit.

See [Object storage](docs/object-storage.md) and the
[Vercel Blob FAL manual](docs/vercel-blob-fal-driver.md).

## Visual Editing And Languages

The community `friendsoftypo3/visual-editor` extension is installed and Camino
is configured for inline editing. Sign in to the TYPO3 backend and open
**Content > Editor**. A short, captioned demonstration is embedded on the
frontend at `/visual-editor` and stored in this repository.

The complete seeded site has strict, connected translations in German, Spanish,
Simplified Chinese, and Hungarian: 9 page records, all 52 content elements, all
18 nested Camino list items, and the related images in every language. Use the
language selector in the Visual Editor to edit one language or compare it with
English. Strict mode never shows English content as an accidental fallback when
a translated record is missing.

New empty databases receive the Visual Editor page and translations during
automatic setup. For an existing database with shell access, run this once:

```bash
vendor/bin/typo3 webconsulting:camino-demo:setup --flush-caches
```

On Vercel, where there is no interactive container shell, call the protected
maintenance endpoint after deploying the new code:

```bash
curl --request POST \
  --header "Authorization: Bearer $CRON_SECRET" \
  https://your-project.vercel.app/api/maintenance/camino-demo.php
```

Database-backed pages and translations are temporary in the one-click SQLite
test. Connect a real database before editors make lasting changes. See
[Visual Editor and translations](docs/visual-editor-and-translations.md).

## Cold Starts

The original public demo showed roughly 10 to 12 second first responses after
Vercel had scaled the container to zero. Warm frontend and backend requests were
normally below half a second. The project now addresses the cold path in four
layers:

1. The TYPO3 image moved from Debian Apache/mod_php to Alpine nginx/PHP-FPM and
   remains about 51% smaller at 465 MB after adding targeted cache artifacts.
2. TYPO3's DI container and 597 Fluid templates are warmed during the image
   build and restored at startup without running Composer or TYPO3 CLI there.
   In a three-run local A/B, first backend work fell from a 1.87s median to
   0.38s; first frontend work fell from 9.66s to 7.27s.
3. The Solr image fell from about 843 MB to about 589 MB. Five clean local
   starts were 1.94-3.21 seconds with a 2.48-second median, versus 4.1-4.6
   seconds before.
4. `vercel.pro.json` runs a protected warm-up every three minutes. It primes the
   TYPO3 frontend, `/typo3/`, the database, Redis, and Solr on the instances
   selected for that invocation.

**Measured result:** after the image reduction, the first `/typo3/` request on
the production deployment still took 11.87 seconds. The final full cold warmer
absorbed both TYPO3 and Solr activation in 26.82 seconds; its immediate repeat
took 0.70 seconds. A 30-request warm backend run had a 0.208-second median and
0.251-second p95, but one separate fresh instance still took 8.85 seconds. The
image work alone did not remove Vercel's activation floor; Pro warming and edge
caching are mitigations, not a minimum-instance guarantee.

Long-run logs also showed that the cron did not reliably keep the separate demo
Solr service resident: three consecutive scheduled checks still spent about
15-17 seconds starting Solr. The demo search now keeps one retry client alive
and waits up to 25 seconds for that cold service; the gateway may still create
several HTTP connections. Production search should use managed, always-on Solr.

The final production acceptance after fixing Solr's data-readiness gate returned
all six results on the first cold search in 16.36 seconds, with no warming or
empty-result state. Its immediate repeat took 0.96 seconds.

The final release check measured 0.143s median for 20 warm frontend requests,
0.255s for 20 warm backend requests, and 0.372s for 30 post-warm searches.

The cache warm-up is intentionally part of the container image build, not a
Composer script and not a blocking runtime-start command. See
[Cold starts and performance](docs/performance.md) for the A/B data and safety
boundaries.

Deploy the Pro configuration with:

```bash
VERCEL_SCOPE=webconsulting scripts/deploy-pro.sh
```

For your own account, omit `VERCEL_SCOPE` or set it to your team slug. Set a
long random `CRON_SECRET`; Vercel Cron sends it as a Bearer token:

```bash
openssl rand -hex 32
vercel env add CRON_SECRET production
```

Hobby permits cron only once per day, so the three-minute warm-up is Pro-only.
Vercel currently exposes no minimum-instance setting for this Container Image
path. The warm-up greatly reduces normal user-visible cold starts but is not a
formal zero-cold-start guarantee during deployments, scaling, failures, or cron
delays. An always-on PHP host is still the strict solution when that guarantee
is mandatory.

The one-click SQLite profile enables a 300-second CDN TTL automatically. For a
durable database-backed site, edge caching remains opt-in because editors may
expect immediate publication and pages may contain forms or personalization:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=300
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=600
```

Only cookie-free `GET`/`HEAD` HTML without a query string is cached. `/typo3/`,
`/api/`, responses with `Set-Cookie`, forms, and personalized requests are never
made public by this middleware. Cached responses explicitly vary on Cookie and
Authorization so Vercel cannot reuse the anonymous representation for those
requests. The policy also wraps Static File Cache so its fallback cannot bypass
these private-response rules. Content can remain cached for the selected TTL,
so keep it disabled for workflows that require immediate publication.

Read [Cold starts and performance](docs/performance.md) for benchmarks and cost
estimates.

## Redis

Redis is optional. It makes TYPO3 page, hash, and rootline caches shared across
instances, but it does not fix cold container activation, replace SQL, or make
uploads durable. Use a `redis://` or `rediss://` TCP endpoint near `fra1`:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

The public demo uses the free **Upstash for Redis** Marketplace plan in `fra1`.
Connect it with the `TYPO3_` prefix so Vercel injects `TYPO3_REDIS_URL`; disable
auto-upgrade if the demo must remain free. REST-only credentials do not work
with TYPO3's native Redis backend. See [Redis cache](docs/redis-cache.md).

## Solr Search

The repository includes EXT:solr 14 beta, Apache Solr 10 configuration, a Camino
search page, Scheduler integration, and a separate Vercel Solr Container Image.
The internal service is useful for demonstrations and self-seeds six Camino
documents when an instance starts.

It is **not durable production Solr**. Vercel Blob is object storage and cannot
be mounted as Solr's low-latency live Lucene index at `/var/solr`. Vercel does
not currently offer a managed durable Solr service or persistent service volume.
Production must use external managed Solr 10 or always-on infrastructure with a
durable volume, backups, monitoring, and access control.

Local benchmark summary on the current code:

| Operation | Result |
|---|---:|
| Direct Solr query, 50 runs | 3.1 ms median, 7.0 ms p95 |
| Update plus commit, 20 runs | 34.3 ms median, 38.1 ms p95 |
| TYPO3 rebuild of six demo pages, 10 runs | 1.11 s median, 1.55 s max |
| Complete TYPO3 search page, 30 runs | 27.2 ms median, 28.8 ms p95 |

The warm search path is fast enough. Service activation and non-durable index
state are the reasons not to use the internal service for serious production.
See [Solr search](docs/solr.md).

## Scheduler And Long Jobs

There is no persistent Linux cron daemon inside a scaled-to-zero container. The
protected endpoint `/api/cron/typo3-scheduler.php` runs TYPO3 Scheduler from
Vercel Cron.

- `vercel.json`: one TYPO3 service, no Solr and no scheduled jobs
- `vercel.pro.json`: three-minute warm-up and 15-minute Scheduler call
- external managed Solr: process bounded index queue batches per invocation
- multi-hour indexing: use an always-on worker, CI job, or provider job runner

One Vercel request is not a multi-hour process supervisor. See
[Scheduler](docs/scheduler.md) and [Long-running jobs](docs/long-running-jobs.md).

## Deploy Now

A push starts a deployment; it does not become live immediately. Wait until the
deployment says **Ready** and the production alias has moved.

Dashboard: open **Deployments**, select the desired commit, open its menu, and
choose **Redeploy**.

CLI, one-click/test configuration:

```bash
vercel deploy --prod --scope webconsulting --yes
```

CLI, Pro warm-up configuration:

```bash
VERCEL_SCOPE=webconsulting scripts/deploy-pro.sh
```

Git-based deployments read `vercel.json`, which intentionally remains
Hobby-compatible. The public Pro demo must therefore be deployed with
`scripts/deploy-pro.sh` after changes if the frequent warm-up is required. The
script creates a clean temporary archive of the committed revision and makes
`vercel.pro.json` its canonical `vercel.json`; it never modifies the checkout.
Confirm the active production schedules after every deployment:

```bash
vercel crons ls --scope webconsulting
```

The Pro deployment must list both the three-minute warm-up and the 15-minute
Scheduler job. If it lists neither job, the latest deployment used the
one-click configuration and cold-start warming is not active.

## Local Development

DDEV uses PHP 8.4 and local Solr 10:

```bash
ddev start
ddev composer install
ddev solrctl apply
ddev exec vendor/bin/typo3 webconsulting:solr-demo:setup --index --scheduler-task
```

Run the complete local verification:

```bash
ddev exec Build/Scripts/runTests.sh -s all
Build/Scripts/runTests.sh -s containers
```

Some TYPO3 configuration-writing CLI actions and a clean Composer install can
replace `config/system/settings.php` with local values. The container build
overlays the committed file after Composer, and CI explicitly restores it
before testing. The test and Docker build then fail unless it still delegates
to `scripts/typo3-env.php`; never commit a generated database password or
development encryption key there.

Docker Compose remains available for a MariaDB smoke test:

```bash
docker compose up --build
```

## What Works

- TYPO3 14.3, Camino, all TYPO3 CMS system packages, and PHP 8.4
- durable PostgreSQL or MySQL-compatible databases
- stable backend sessions with a durable database
- Vercel Blob and S3-compatible TYPO3 FAL storage
- secure direct-to-Blob uploads above the normal 4 MB request limit
- inline Camino editing with `friendsoftypo3/visual-editor`
- complete strict German, Spanish, Simplified Chinese, and Hungarian content translations
- ImageMagick, AVIF, WebP, Ghostscript, and writable `/tmp` processing paths
- Vercel Marketplace Redis through a TCP/TLS connection
- protected Vercel Cron endpoints
- protected, idempotent existing-database setup for the Camino Visual Editor demo
- EXT:solr with internal demo or external managed Solr 10
- Vercel Firewall/WAF in front of the public application
- Pro performance CPU and `fra1` region configuration for the public demo

## What Does Not Work Automatically

- durable TYPO3 database state in the initial SQLite-only clone
- durable local `var/`, `fileadmin/`, Solr index, or ImageMagick temp files
- reliable backend sessions without a shared SQL database
- files above 4 MB through the normal uploader; use **Media > Large upload** for Blob
- a Linux daemon, multi-hour Scheduler request, or always-on worker
- durable production Solr inside the Vercel service without a persistent volume
- guaranteed zero cold starts on Hobby or without an always-on/minimum-instance feature
- outgoing email without an external SMTP provider
- GDPR compliance by configuration alone

## Documentation

Start with the [documentation index](docs/README.md). Important guides:

- [Quickstart](docs/quickstart.md)
- [Vercel deployment](docs/vercel.md)
- [Performance and cold starts](docs/performance.md)
- [Database](docs/database.md)
- [Object storage](docs/object-storage.md)
- [Vercel Blob FAL driver](docs/vercel-blob-fal-driver.md)
- [Visual Editor and translations](docs/visual-editor-and-translations.md)
- [Redis](docs/redis-cache.md)
- [Solr](docs/solr.md)
- [Scheduler](docs/scheduler.md)
- [Limitations](docs/limitations.md)
- [Production hardening](docs/production-hardening.md)
- [Vercel product manager summary](docs/vercel-product-manager-summary.md)

Current plan limits and product behavior can change. Verify them against the
[Vercel Container Images](https://vercel.com/docs/functions/container-images),
[Cron Jobs](https://vercel.com/docs/cron-jobs),
[Function limits](https://vercel.com/docs/functions/limitations), and
[Blob pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing) pages.
