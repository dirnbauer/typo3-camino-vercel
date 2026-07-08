# Apache Solr Search

## Short Answer

Vercel does not currently provide a managed Apache Solr service for TYPO3.

This repo supports EXT:solr, but production Solr should run outside the TYPO3
Vercel container on a managed Solr provider or on always-on infrastructure with
durable storage, backups, monitoring, and access control.

## Current TYPO3 Compatibility

Checked for TYPO3 14.3:

- TYPO3: `14.3`
- EXT:solr: `14.0.0-beta3`
- Apache Solr: `10.0.0`
- Solr configset: `ext_solr_14_0_0`
- Composer package: `apache-solr-for-typo3/solr:^14.0@beta`

EXT:solr 14 is still a beta line. That is the current compatible line for
TYPO3 14.3, so this starter pins the Composer constraint to `^14.0@beta`.

## What Was Added To This Repo

Composer:

```text
apache-solr-for-typo3/solr:^14.0@beta
```

Runtime config writer:

```text
scripts/apply-solr-config.php
```

The Vercel entrypoint runs that script only when Solr env vars are present.
Nothing changes for normal deploys without Solr.

By default, the script writes Solr connection settings only. It does not inject
EXT:solr frontend site set dependencies into the Camino site unless
`TYPO3_SOLR_APPLY_SITE_SET=1` is set. This keeps the Camino demo frontend stable
while still allowing the internal Solr service to run for experiments.

When frontend search is enabled, this repo uses a small local site set by
default:

```text
webconsulting/typo3-vercel-solr-demo
webconsulting/typo3-vercel-solr-demo-stylesheets
```

That site set imports EXT:solr TypoScript and maps a dedicated
`vercel_solr_demo_results` content element to a small Camino demo renderer:

```text
Webconsulting\Typo3VercelSolrDemo\Content\SolrSearchContent
```

The renderer still queries Solr. With the internal Vercel Solr service it
filters to the self-seeded `siteHash:"vercel-demo"` documents and catches
Vercel service warmup failures so the page does not render a TYPO3 exception.
With an external managed Solr connection it searches normal `type:pages`
documents unless `TYPO3_SOLR_DEMO_SITE_HASH` is set explicitly. This is still
deliberately demo-specific. For normal production TYPO3 search, use the stock
EXT:solr frontend plugin with a managed Solr endpoint.

The local site set also maps the auxiliary EXT:solr plugin content element
types (`solr_pi_search`, `solr_pi_frequentlysearched`) directly to Extbase
plugin rendering without depending on `typo3/fluid-styled-content`. The local
demo extension applies the same mapping in `ext_localconf.php`, after EXT:solr
registers its plugins. This matters because TYPO3's default Extbase plugin
mapping expects a `Generic` Fluid Styled Content template, while Camino ships
its own content rendering. The official EXT:solr site set depends on Fluid
Styled Content, which conflicts with Camino's rendering assumptions and can
turn Camino pages into missing template errors after a TYPO3 cache flush. You
can still opt into the official set with `TYPO3_SOLR_SITE_SET`, but do not do
that for this Camino starter.

Demo search setup package:

```text
packages/typo3-vercel-solr-demo/
```

It provides the TYPO3 CLI command:

```bash
vendor/bin/typo3 webconsulting:solr-demo:setup
```

The command creates a `/search` page, adds or migrates the demo results content
element (`vercel_solr_demo_results`), stores `search.targetPage` in the content
element FlexForm, initializes the EXT:solr index queue, and can process a small
queue batch. It can also create or update the real EXT:solr Index Queue Worker
Scheduler task when called with `--scheduler-task`.

Protected Vercel indexing endpoint:

```text
public/api/cron/typo3-solr-demo.php
```

It requires `Authorization: Bearer <CRON_SECRET>` and runs the setup command
after deploy. With the internal Vercel demo Solr service it creates/updates the
search page but skips runtime indexing by default, because the Solr service
self-seeds the static Camino demo documents on startup. With an external
managed Solr connection, the endpoint runs a small bounded index by default.
Set `TYPO3_SOLR_INDEX_ON_SETUP=1` to force bounded runtime indexing, or `0` to
disable it. Add `scheduler=1` to the protected endpoint or set
`TYPO3_SOLR_SCHEDULER_TASK=1` to create/update the EXT:solr Index Queue Worker
task. This is for small demo/batch indexing, not multi-hour jobs.

Protected Vercel Scheduler endpoint:

```text
public/api/cron/typo3-scheduler.php
```

`vercel.json` schedules this endpoint daily. With a managed/external Solr
connection, it runs TYPO3's real `scheduler:run` command, so EXT:solr Index
Queue Worker tasks can process small batches. With the internal Vercel Solr
demo service, the endpoint returns a safe skip by default because the Solr
service self-seeds the Camino demo index on startup and runtime writes to that
service are not durable. Set `TYPO3_SOLR_RUN_INTERNAL_SCHEDULER=1` or call the
protected endpoint with `runInternalSolr=1` only when deliberately testing that
non-durable demo path.

Experimental Vercel demo service:

```text
services/solr/
```

`vercel.json` wires this service as an internal Vercel service binding named
`TYPO3_SOLR_SERVICE_URL`. TYPO3 uses that binding only when
`TYPO3_SOLR_ENABLED=1`. The service self-seeds the static Camino demo
documents into every runtime instance at startup, so the demo search does not
depend on cross-instance runtime writes. The service binds the Vercel port
immediately and starts Solr in the same container, then seeds the demo documents
as soon as `core_en` answers. This keeps Vercel deployment promotion reliable,
but an immediate first Solr request can still see a short cold-start warmup.

Local development:

```text
.ddev/config.yaml
.ddev/docker-compose.typo3-solr.yaml
.ddev/typo3-solr/config.yaml
```

DDEV uses PHP 8.4 and a local Solr service pinned to the TYPO3 14 compatible
Solr/configset line.

## Recommended Production Setup

Use an external managed Solr endpoint close to the Vercel region and database.
For the public demo's current region (`fra1`), choose a European Solr endpoint
if possible.

Add these Vercel environment variables:

```dotenv
TYPO3_SOLR_ENABLED=1
TYPO3_SOLR_SITE_BASE=https://your-project.vercel.app/
TYPO3_SOLR_URL=https://user:password@solr.example.com:443/solr/core_en
TYPO3_SOLR_SITE_IDENTIFIER=camino
TYPO3_SOLR_APPLY_SITE_SET=1
TYPO3_SOLR_SITE_SET=webconsulting/typo3-vercel-solr-demo
TYPO3_SOLR_INCLUDE_STYLESHEETS=1
TYPO3_SOLR_STYLESHEET_SITE_SET=webconsulting/typo3-vercel-solr-demo-stylesheets
TYPO3_SOLR_SEARCH_SLUG=/search
TYPO3_SOLR_INDEX_ON_SETUP=1
TYPO3_SOLR_SCHEDULER_TASK=1
TYPO3_SOLR_SCHEDULER_INTERVAL=300
CRON_SECRET=<long-random-token-for-protected-setup-endpoints>
```

Then run extension setup once after deploying the package:

```dotenv
TYPO3_EXTENSION_SETUP_ON_BOOT=1
```

After one successful boot, set it back to:

```dotenv
TYPO3_EXTENSION_SETUP_ON_BOOT=0
```

Why: extension setup can write database schema and package state. It should not
run on every cold container start unless you are deliberately changing
extensions.

For the built-in seeded SQLite demo, the container image already runs TYPO3
extension setup while creating the seed database. Keep
`TYPO3_EXTENSION_SETUP_ON_BOOT=0` for normal demo deployments. Use the
setup-on-boot cycle above only for a durable external database that was created
before the Solr package/schema was present.

After deploy, create the `/search` page and seed a small demo index with the
protected endpoint:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  "https://your-project.vercel.app/api/cron/typo3-solr-demo.php?limit=50"
```

Do not run this command from public docs or CI logs with the real token printed.

## Environment Variables

Single URL form:

```dotenv
TYPO3_SOLR_URL=https://user:password@solr.example.com:443/solr/core_en
```

Split form:

```dotenv
TYPO3_SOLR_SCHEME=https
TYPO3_SOLR_HOST=solr.example.com
TYPO3_SOLR_PORT=443
TYPO3_SOLR_PATH=/
TYPO3_SOLR_CORE=core_en
TYPO3_SOLR_USERNAME=<optional-user>
TYPO3_SOLR_PASSWORD=<optional-password>
```

`TYPO3_SOLR_PATH` is the prefix before Solarium's `solr` context. For a normal
Solr core URL such as `https://solr.example.com/solr/core_en`, the EXT:solr site
path must be `/`, not `/solr/`. The config writer strips a trailing `/solr/`
from split-form paths to avoid the broken `/solr/solr/core_en/` request shape.

Optional separate write connection:

```dotenv
TYPO3_SOLR_USE_WRITE_CONNECTION=1
TYPO3_SOLR_WRITE_URL=https://user:password@solr-write.example.com:443/solr/core_en
```

Optional multi-language cores:

```dotenv
TYPO3_SOLR_CORE_LANGUAGE_0=core_en
TYPO3_SOLR_CORE_LANGUAGE_1=core_de
```

Optional all-site application:

```dotenv
TYPO3_SOLR_SITE_IDENTIFIER=all
```

Experimental internal Vercel demo service:

```dotenv
TYPO3_SOLR_ENABLED=1
TYPO3_SOLR_SITE_BASE=https://your-project.vercel.app/
TYPO3_SOLR_SITE_IDENTIFIER=camino
TYPO3_SOLR_CORE=core_en
TYPO3_SOLR_APPLY_SITE_SET=1
TYPO3_SOLR_SITE_SET=webconsulting/typo3-vercel-solr-demo
TYPO3_SOLR_STYLESHEET_SITE_SET=webconsulting/typo3-vercel-solr-demo-stylesheets
TYPO3_SOLR_SEARCH_SLUG=/search
TYPO3_SOLR_INDEX_ON_SETUP=0
CRON_SECRET=<long-random-token-for-protected-setup-endpoints>
```

Do not set `TYPO3_SOLR_URL` for the internal demo service. Vercel injects
`TYPO3_SOLR_SERVICE_URL` through the binding from the `app` service to the
`solr` service. `scripts/apply-solr-config.php` maps that service root to
`solr_path_read: /` and `solr_core_read: core_en`; Solarium/EXT:solr then
requests the real core at `/solr/core_en/`.

Enable it on Vercel with:

```bash
vercel env add TYPO3_SOLR_ENABLED production --value 1 --force --yes
vercel env add TYPO3_SOLR_SITE_BASE production --value "https://typo3-camino-vercel.vercel.app/" --force --yes
vercel env add TYPO3_SOLR_SITE_IDENTIFIER production --value camino --force --yes
vercel env add TYPO3_SOLR_CORE production --value core_en --force --yes
vercel env add TYPO3_SOLR_APPLY_SITE_SET production --value 1 --force --yes
vercel env add TYPO3_SOLR_SITE_SET production --value webconsulting/typo3-vercel-solr-demo --force --yes
vercel env add TYPO3_SOLR_STYLESHEET_SITE_SET production --value webconsulting/typo3-vercel-solr-demo-stylesheets --force --yes
vercel env add TYPO3_SOLR_SEARCH_SLUG production --value /search --force --yes
vercel env add TYPO3_SOLR_INDEX_ON_SETUP production --value 0 --force --yes
vercel env add CRON_SECRET production --sensitive --force
vercel env add TYPO3_EXTENSION_SETUP_ON_BOOT production --value 0 --force --yes
vercel deploy --prod --regions fra1
```

The seeded SQLite demo DB in the image already contains extension schema. For an
existing durable database that was created before EXT:solr was present, run the
one-time extension setup cycle from the production section first.

## What The Script Writes

`scripts/apply-solr-config.php` updates `config/sites/<site>/config.yaml` with:

- optional site set dependency from `TYPO3_SOLR_SITE_SET` when
  `TYPO3_SOLR_APPLY_SITE_SET=1`; default:
  `webconsulting/typo3-vercel-solr-demo`
- optional stylesheet site set from `TYPO3_SOLR_STYLESHEET_SITE_SET` when both
  `TYPO3_SOLR_APPLY_SITE_SET=1` and `TYPO3_SOLR_INCLUDE_STYLESHEETS=1`;
  default: `webconsulting/typo3-vercel-solr-demo-stylesheets`
- read Solr connection settings
- optional write Solr connection settings
- per-language `solr_core_read`
- optional absolute `base` when `TYPO3_SOLR_SITE_BASE` is set

It does not store secrets in Git. The generated production config happens at
container boot from Vercel environment variables.

## Create The Search Page And Index

Do not create the frontend search page at Vercel container boot. It works
locally, but it adds TYPO3 CLI/database work to every cold start and caused
`INTERNAL_FUNCTION_INVOCATION_FAILED` during testing. This repo intentionally
does not support boot-time Solr page creation/indexing in the Vercel entrypoint.

Use the protected endpoint after deploy instead. It runs the same TYPO3 CLI
command inside the Vercel app container, but only when explicitly called:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  "https://your-project.vercel.app/api/cron/typo3-solr-demo.php?limit=50"
```

For managed/external Solr, create or update the TYPO3 Scheduler task at the
same time:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  "https://your-project.vercel.app/api/cron/typo3-solr-demo.php?limit=50&scheduler=1&schedulerInterval=300"
```

The setup command flushes TYPO3 caches automatically when it creates or changes
the search page. The endpoint also normalizes the seeded Camino demo pages for
indexing. For external managed Solr, it initializes the EXT:solr page queue and
indexes a bounded batch by default. For the internal Vercel demo Solr service,
runtime indexing is skipped by default and the service startup seed provides
the reliable static demo search index.

Manual CLI form, useful locally or on a worker:

```bash
vendor/bin/typo3 webconsulting:solr-demo:setup --index --limit=50
vendor/bin/typo3 webconsulting:solr-demo:setup --scheduler-task --limit=50 --scheduler-interval=300
```

The endpoint:

- creates or updates the `/search` page
- creates or updates the `vercel_solr_demo_results` content element
- migrates an older `solr_pi_results` demo row to the dedicated demo CType
- stores the Solr target page in the content element FlexForm
- flushes TYPO3 caches so stale route/page caches do not hide the new page
- initializes the EXT:solr `pages` index queue when runtime indexing is enabled
- processes up to `limit` queue documents, capped at 200 per request, when
  runtime indexing is enabled
- creates or updates the EXT:solr Index Queue Worker Scheduler task when
  `scheduler=1`, `TYPO3_SOLR_SCHEDULER_TASK=1`, or `--scheduler-task` is used
- persists that Scheduler task in TYPO3 14's normal JSON field format, so the
  protected Vercel endpoint can create it without a backend form submission
- falls back to direct Camino demo page indexing when the beta EXT:solr queue
  worker cannot render Camino pages in the Vercel context and runtime indexing
  is enabled
- retries short Solr `502`/`503` write failures and reports a same-connection
  post-commit document count when runtime indexing is enabled

For larger sites, call the endpoint repeatedly with a safe limit or use the
normal TYPO3 Scheduler/worker approach described below.

Important: with the internal Vercel Solr service, the reliable demo search data
is the self-seeded static Camino index that runs inside each Solr service
instance at startup. Runtime writes from TYPO3 to a separate Vercel Solr service
can succeed on one service instance while a later select request reaches
another fresh instance. That is why production indexing should use external
managed Solr.

### Current Demo Test Result

Live production deployment checked on 2026-07-08:

- The public alias points to a ready Vercel deployment with both `app` and
  `solr` container outputs.
- `GET /` returned `200`. A cold post-deploy hit took about 14.6s; a later
  warm hit took about 2.5s in the same test window.
- Protected runtime diagnostics returned `200` and confirmed `var`,
  `public/fileadmin`, `public/typo3temp`, and `/tmp/typo3/var/lock` are
  writable runtime paths.
- The protected setup endpoint returned `200`, created/confirmed `/search`,
  updated Scheduler task uid `1`, and skipped runtime indexing because the
  internal Vercel Solr demo service is self-seeded.
- The protected Scheduler endpoint returned `200`. For the internal Vercel
  Solr demo service it intentionally skips EXT:solr runtime indexing; with
  managed/external Solr it runs TYPO3's real `scheduler:run`.
- Protected Solr probes returned `200` and saw the six self-seeded Camino demo
  documents. During a fresh Solr service cold start, an immediate probe can
  still miss the service until `core_en` is ready.
- Public `/search?tx_solr[q]=Camino` returned `200` with no TYPO3 Oops. The
  final warm result check returned six result entries in about 0.57s:
  `Camino`, `Camino Route Comparison`, `Packing List`, `Imprint`, `FAQs`, and
  `Privacy`.
- The repo now maps the demo `vercel_solr_demo_results` content element to
  `Webconsulting\Typo3VercelSolrDemo\Content\SolrSearchContent`, a small
  Camino-specific renderer that still queries Solr but catches service warmup
  and avoids a frontend exception.
- The renderer is marked with TYPO3 14's `#[AsAllowedCallable]` attribute.
  Without that, TYPO3 rejects TypoScript `userFunc` calls before the renderer's
  own error handling can run.
- Older demo content rows using `solr_pi_results` are migrated by the protected
  setup endpoint so the stock EXT:solr result plugin no longer controls the
  Vercel demo search page response status.
- The demo renderer uses short internal Solr HTTP timeouts. Override the
  default 6 second per-attempt timeout with `TYPO3_SOLR_DEMO_REQUEST_TIMEOUT`
  if needed; values are clamped between 1 and 10 seconds.
- The Vercel PHP image includes the PHP cURL extension and the renderer prefers
  cURL over PHP streams for internal Solr calls, because cURL handles timeout
  and connection-close behavior more predictably here.
- The protected setup endpoint created or confirmed `/search`.
- Runtime indexing against the internal Vercel Solr service can write
  successfully but then read `numFound: 0` because updates and selects may reach
  different fresh service instances.
- For that reason, the protected setup endpoint skips runtime indexing by
  default when it detects the internal Vercel Solr service. Set
  `TYPO3_SOLR_INDEX_ON_SETUP=1` only when deliberately testing bounded runtime
  indexing or when using managed external Solr.
- The internal Solr service now self-seeds the static Camino demo search index
  on startup so each service instance can answer the demo search without
  relying on cross-instance runtime index state.
- The Solr service binds the Vercel port immediately for reliable deployment
  promotion and seeds the static demo documents as soon as `core_en` is ready.
  An immediate first Solr request can still hit service warmup; warm requests
  should return normally.

Important limitation: the internal Vercel Solr service stores the index in
runtime `/tmp`. The startup seed makes the static demo searchable, but this is
still only acceptable for this demo/experiment path. Production search needs a
managed/external Solr 10 endpoint with durable index storage.

### Troubleshooting `/solr/solr/core_en/`

If the TYPO3 backend says it is trying to contact a URL ending in
`/solr/solr/core_en/`, the site config path is wrong. The TYPO3 site config
should look like this for a normal Solr core:

```yaml
solr_path_read: /
languages:
  -
    solr_core_read: core_en
```

The path must not contain `/solr/` because Solarium has a separate `context`
option with the default value `solr`.

## Local Development With DDEV

Start DDEV and install Composer dependencies inside PHP 8.4:

```bash
ddev start
ddev composer install
```

Create the local Solr core/configset:

```bash
ddev solrctl apply
```

Apply local TYPO3 site config for the DDEV Solr service:

```bash
ddev exec env \
  TYPO3_SOLR_ENABLED=1 \
  TYPO3_SOLR_URL=http://typo3-solr:8983/solr/core_en \
  TYPO3_SOLR_SITE_BASE=https://typo3-camino-vercel.ddev.site/ \
  TYPO3_SOLR_APPLY_SITE_SET=1 \
  TYPO3_SOLR_SITE_SET=webconsulting/typo3-vercel-solr-demo \
  TYPO3_SOLR_STYLESHEET_SITE_SET=webconsulting/typo3-vercel-solr-demo-stylesheets \
  php scripts/apply-solr-config.php
```

The local site set is intentionally not the official EXT:solr site set. It
imports the Solr plugin TypoScript without replacing Camino's content rendering.

Run TYPO3 extension setup:

```bash
ddev exec vendor/bin/typo3 extension:setup --no-interaction
ddev exec vendor/bin/typo3 cache:flush
```

Create the demo search page and index a first small batch:

```bash
ddev exec vendor/bin/typo3 webconsulting:solr-demo:setup --index --limit=50
```

Use DDEV's generated service output instead of guessing URLs:

```bash
ddev describe
ddev solr-admin
```

## Scheduler Requirement

EXT:solr indexing depends on TYPO3 Scheduler tasks. Vercel does not run a
Linux cron daemon in this container. Use:

- the protected `/api/cron/typo3-scheduler.php` endpoint
- the daily Vercel Cron entry already included in `vercel.json`
- a faster Vercel Cron schedule on Pro/Enterprise when the cadence fits
- an external HTTPS cron service for more frequent indexing

See [scheduler.md](scheduler.md).

### Large Indexing Jobs

Indexing a whole website can take minutes or hours depending on page count,
language count, rendering cost, Solr latency, and whether files are extracted
with Tika. Do not run that as one Vercel request.

Use EXT:solr's Index Queue Worker in chunks:

- queue the content for indexing in the Solr backend module
- configure "Number of documents to Index" to a bounded value
- run Scheduler repeatedly until the queue is empty
- measure one batch and keep it well below Vercel's invocation limit

Start with 25-100 documents per batch for normal pages. Use 10-50 for large or
multi-language sites. Use 1-10 for file/Tika indexing, and prefer an external
worker for those jobs.

For hour-scale full reindexing, run the Scheduler CLI from an external worker
that has the same production database and Solr environment variables:

```bash
vendor/bin/typo3 scheduler:list
vendor/bin/typo3 scheduler:execute <index-queue-worker-task-id> --no-interaction
```

See [long-running jobs](long-running-jobs.md).

## Can Solr Run As A Vercel Container?

Yes, for this repo's demo path. `vercel.json` defines an internal `solr`
service and the `app` service has a private service binding to it. The Solr
service is not exposed by a public rewrite.

```text
TYPO3 app service
  -> TYPO3_SOLR_SERVICE_URL binding
  -> internal Solr service
```

The service uses:

- `typo3solr/ext-solr:14.0.0-beta3`
- Apache Solr 10.0.0
- configset `ext_solr_14_0_0`
- enabled cores `core_en` and `core_de`
- a startup seed for the six static Camino demo documents

This is still not a production Solr architecture by default.

Solr needs durable index state in `/var/solr`, predictable startup, private
networking or strong access control, monitoring, backups, and upgrade handling.
A disposable Solr container can be useful for experiments, but it is not the
same as managed Solr.

The Solr service files live here:

```text
services/solr/
```

Do not treat this service as production search without solving durability,
backup/restore, monitoring, memory sizing, and reindex operations first.

## Buying/Choosing A Solr Provider

Practical options:

- hosted-solr.com
- OpenSolr
- SearchStax
- a TYPO3 host or agency-managed Solr service
- self-managed Solr 10 on an always-on VM/container platform

The cheapest public entry plans checked during this work were roughly
10-15 EUR/month. Treat that as orientation only. Provider prices, document
limits, regions, backups, and support levels change.

No paid Solr provider was purchased from this repo because Vercel does not
offer a first-party managed Apache Solr product here, and buying an external
provider requires account, billing, region, SLA, and data-processing decisions.

## What Is Not Included

- no production Solr server is deployed by default
- no Solr credentials are committed
- no file indexing/Tika setup is enabled
- no Solr index backup/restore automation is included
- no multi-hour Vercel worker is included for full reindexing
- no guarantee that EXT:solr beta is acceptable for every production project

## References

- EXT:solr version matrix: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html
- EXT:solr site sets: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Configuration/SiteSets.html
- EXT:solr 14 release notes: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Releases/solr-release-14-0.html
- EXT:solr Packagist package: https://packagist.org/packages/apache-solr-for-typo3/solr
- Vercel Services: https://vercel.com/docs/services
- Vercel service bindings: https://vercel.com/docs/services/bindings
- Vercel Container Images: https://vercel.com/docs/functions/container-images
- Vercel Container Registry: https://vercel.com/docs/container-registry
- Scheduler and cron in this repo: scheduler.md
