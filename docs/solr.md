# Apache Solr Search

## Decision

The repository supports two Solr modes:

| Mode | Purpose | Index durability |
|---|---|---|
| Internal Vercel Solr service | Demo, experiments, integration tests | No; self-seeded on each instance |
| External Solr 10 endpoint | Real indexing and production search | Provider/volume dependent |

Vercel offers no managed Apache Solr product and no persistent mounted volume
suitable for Solr's live Lucene index. Production Solr should run on a managed
provider or always-on infrastructure with durable storage, backups, monitoring,
and access control. Confirm the provider actually offers Solr 10: EXT:solr 14
requires it, and many managed vendors still run Solr 9.

## Version Set

The Composer lock and service image currently use:

- TYPO3 CMS `14.3.5`
- EXT:solr `14.0.0-beta3`
- Apache Solr `10.0.0`
- EXT:solr configset `ext_solr_14_0_0`
- Composer constraint `apache-solr-for-typo3/solr:^14.0@beta`

EXT:solr 14 is still a beta dependency. Recheck the official version matrix and
switch to a stable constraint when a compatible stable release is published.

## What Is Included

- EXT:solr and its TYPO3 backend modules
- a Camino-compatible site set and search renderer
- a CLI command that creates `/search`, configures the content element,
  initializes the queue, indexes a bounded set, and creates a Scheduler task
- a protected setup/diagnostic/benchmark endpoint
- a protected TYPO3 Scheduler endpoint
- a DDEV Solr 10 service for local development
- a separate private Vercel container Service running real Solr 10
- a private service binding from TYPO3 to Solr
- a bounded retry proxy for Vercel service activation responses
- six localized Camino demo documents in each of five self-seeded cores
- native EXT:solr autocomplete using `fetch`, `AbortController`, and
  `autoComplete.js`, with Camino styling and no jQuery

## Search Suggestions

External Solr mode uses EXT:solr's official browser-side
`suggest_controller.js` and `autocomplete.min.js`. A small JSON adapter at page
type `7384` queries the configured Solr core and returns the response shape that
the official controller expects. It preserves the localized search path,
requires two characters, caps input at 50 characters, removes punctuation,
deduplicates titles, and limits the top-document list to four records.

The internal demo service is a special case: each language contains six
immutable, self-seeded documents, so the search page embeds the matching
localized catalog and ranks it with a small accessible native controller.
Typing therefore makes no request and cannot cold-start PHP or the Solr JVM.
Full result pages still query the matching Apache Solr core. When
`TYPO3_SOLR_URL` points to external production Solr, suggestions use the live
index instead. The search partial registers the selected controller through
TYPO3's AssetCollector, keeping delivery coupled to the component.

The adapter exists because EXT:solr 14.0.0-beta3's Extbase suggest action
returned `RequiredArgumentMissingException` for `queryString` even with the
documented request namespace. Neither path replaces the main EXT:solr search or
indexing implementation; recheck the adapter on the stable EXT:solr 14 release.

## Internal Vercel Service

`vercel.pro.json` defines two services; the one-click `vercel.json` omits Solr:

```text
app  --private TYPO3_SOLR_SERVICE_URL binding-->  solr
```

The Solr service is not exposed through a public rewrite. Its image:

1. takes the official EXT:solr 14 config from
   `typo3solr/ext-solr:14.0.0-beta3`
2. runs it on `solr:10.0-slim`
3. copies only the required `analysis-extras`, `langid`, `language-models`,
   `scripting`, `clustering`, and `extraction` modules
4. enables `core_en`, `core_de`, `core_es`, `core_zh`, and `core_hu`
5. uses a 256 MB initial and 512 MB maximum Java heap
6. stores writable state below `/tmp/solr-home`

The service-local nginx binds the Vercel port immediately while Java starts.
It exposes internal health paths:

```text
/__health/live
/__health/ready
```

Readiness returns success only after `core_en` answers a query and all 30 demo
documents have been committed and counted across five cores. Until then, every
externally bound Solr path returns `503 starting`; seeding uses the service-local
Solr port and does not pass through that gate. Startup logs contain structured
`startup` and `ready` records with the measured duration and verified document
and core counts.

### Multilingual Core Routing

Each TYPO3 site language uses the matching official EXT:solr schema:

| TYPO3 language ID | Language | Solr core |
|---:|---|---|
| 0 | English | `core_en` |
| 1 | German | `core_de` |
| 2 | Spanish | `core_es` |
| 3 | Simplified Chinese | `core_zh` |
| 4 | Hungarian | `core_hu` |

The site config writer adds `solr_core_read` to every language, and the custom
demo result renderer independently resolves the active request language before
building its core URL. The duplicated mapping is intentional: EXT:solr backend
modules and the Camino result renderer must agree. `TYPO3_SOLR_CORE` and
`TYPO3_SOLR_CORE_LANGUAGE_<id>` override the default core mapping when a
provider uses different core names.

Release and production acceptance verified language isolation: German
`q=inhalte` returned the localized document from `core_de` and zero from
`core_en`, native terms matched in all five languages, and `q=*` returned six
records per language. A cold first request still took about 16 seconds while
the service activated; warm repeats had 0.35-0.67s TTFB. Language routing does
not remove the independent service cold start.

The optimized five-core image is about 589 MB (down from 843 MB) and reaches
local readiness in roughly 2-4 seconds; loading and seeding five schemas is
startup work, not image size.

## Why The Demo Self-Seeds

Vercel can start several Solr service instances. Their `/tmp` filesystems are
not shared, so an update can reach one instance and the next select can reach a
fresh instance with zero documents.

The service avoids a broken demo by inserting six localized Camino documents
per core when every instance becomes ready. This makes static demo search
repeatable, but it does not make editor-driven runtime indexing durable.

By default, the protected setup endpoint detects the internal service and skips
the TYPO3 runtime index write. Set `TYPO3_SOLR_INDEX_ON_SETUP=1` only when you
deliberately want to test that transient path.

## Service Activation And Retry Path

During activation, the Vercel service gateway can answer `500 Starting...`
before the request reaches the container. Once nginx is running, it can briefly
return `502` or `503` while Java is still starting.

TYPO3 therefore connects through a loopback-only proxy in the app container:

```text
EXT:solr
  -> http://127.0.0.1:<PORT>/api/solr-proxy.php/solr/core_en/...
  -> private TYPO3_SOLR_SERVICE_URL
  -> service nginx
  -> Solr core_en
```

The proxy retries only startup-class `500`, `502`, `503`, and `504` responses.
Per-attempt timeout is four seconds and the complete proxy window is bounded to
20 seconds. Public access to the proxy returns `404`.

The proxy, protected warmer, and Camino renderer each reuse one cURL handle
through their retry loop instead of sending `Connection: close`. This gives the
Vercel binding the best chance to keep retries on one activated service
connection rather than starting another cold instance. It is an optimization,
not a connection-affinity guarantee: the gateway may close or reroute requests
between attempts. The Camino renderer has a separate 25-second total startup
budget, configurable with
`TYPO3_SOLR_DEMO_STARTUP_TIMEOUT` and clamped to 5-30 seconds. External managed
Solr keeps its shorter normal request behavior.

The former Pro warm-up cron pinged Solr frequently, but it was not an instance
reservation: after 13 hours on that schedule, consecutive invocations still
found Solr cold (14.6-17.0 seconds of startup). That schedule is now removed.
Production acceptance on
2026-07-11 confirmed the intended behavior: a cold search waited and returned
HTTP 200 with all six documents in 16.4 seconds, and the immediate repeat took
0.96 seconds. Telemetry also showed that cURL handle reuse does not guarantee
binding affinity. The correctness guarantee is therefore the `503 starting`
readiness gate plus the exact six-document seed check — not cron residency and
not connection affinity. The service uses Solr's bundled production logging
configuration.

## Enable The Internal Demo

Deploy `vercel.pro.json` and set production environment variables:

```dotenv
TYPO3_SOLR_ENABLED=1
TYPO3_SOLR_SITE_BASE=https://your-project.vercel.app/
TYPO3_SOLR_SITE_IDENTIFIER=camino
TYPO3_SOLR_APPLY_SITE_SET=1
TYPO3_SOLR_SITE_SET=webconsulting/typo3-vercel-solr-demo
TYPO3_SOLR_INCLUDE_STYLESHEETS=1
TYPO3_SOLR_STYLESHEET_SITE_SET=webconsulting/typo3-vercel-solr-demo-stylesheets
TYPO3_SOLR_SEARCH_SLUG=/search
TYPO3_SOLR_INDEX_ON_SETUP=0
# Optional; default 25, accepted range 5-30 seconds
TYPO3_SOLR_DEMO_STARTUP_TIMEOUT=25
CRON_SECRET=<long-random-secret>
```

Do not set `TYPO3_SOLR_URL` for this mode. Vercel injects
`TYPO3_SOLR_SERVICE_URL` from the service binding. The binding enables Solr
site configuration automatically; `TYPO3_SOLR_ENABLED=1` remains supported
for explicit configuration, while `TYPO3_SOLR_ENABLED=0` is the kill switch.
`TYPO3_SOLR_APPLY_SITE_SET=1` is required for the EXT:solr frontend plugin;
the local `webconsulting/typo3-vercel-solr-demo` site set is used instead of
the official EXT:solr set so Camino is not broken by that set's Fluid Styled
Content dependency.

After deployment, create or repair the `/search` page:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  'https://your-project.vercel.app/api/cron/typo3-solr-demo.php?action=setup'
```

Probe cores, ping, and a select request:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  'https://your-project.vercel.app/api/cron/typo3-solr-demo.php?action=probe'
```

Open:

```text
/search?tx_solr[q]=camino
/de/suche?tx_solr[q]=inhalte
```

The expected result count for `q=*` is six in each language. A genuinely cold
first search may take roughly 15-20 seconds, but it should wait for results
rather than first showing the intermediate warming state.

## External Production Solr

Choose a Solr 10 endpoint near the Vercel region. For the current `fra1` app,
prefer a European Solr region. Configure authentication and TLS:

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
CRON_SECRET=<long-random-secret>
```

When the extension was added to an existing durable database, run extension
setup for one deployment:

```dotenv
TYPO3_EXTENSION_SETUP_ON_BOOT=1
```

Set it back to `0` after a successful deploy. Schema setup should not run on
every cold start.

Call the setup endpoint with a bounded batch and Scheduler task creation:

```bash
curl -fsS \
  -H "Authorization: Bearer $CRON_SECRET" \
  'https://your-project.vercel.app/api/cron/typo3-solr-demo.php?action=setup&index=1&scheduler=1&limit=50'
```

The normal `/api/cron/typo3-scheduler.php` endpoint then runs TYPO3 Scheduler.
`vercel.pro.json` calls it every 15 minutes. Keep the Index Queue Worker batch
small enough to finish within one invocation.

For a normal production site, replace the Camino-specific result renderer with
the stock EXT:solr frontend plugin after validating the site's TypoScript and
templates.

## Local DDEV Setup

DDEV uses PHP 8.5 and the matching Solr 10 configset:

```bash
ddev start
ddev composer install
ddev solrctl apply
```

Create the page and index the local Camino content:

```bash
ddev exec vendor/bin/typo3 webconsulting:solr-demo:setup \
  --index \
  --scheduler-task \
  --normalize-demo-pages \
  --flush-caches \
  --diagnose
```

Useful commands:

```bash
ddev logs -s typo3-solr
ddev exec -s typo3-solr solr --version
ddev launch :8984
```

The current local validation produced six queue items, processed all six, and
returned six indexed documents.

## Index Queue Shows `pages (0 records)`

This symptom was reproduced with EXT:solr 14 beta on PostgreSQL:

```text
Index Queue initialized
Initialized indexing configurations: pages (0 records)
```

The beta initializer can build native SQL with an empty-string expression that
is accepted by MySQL but not PostgreSQL. EXT:solr catches the database error and
the backend can still report successful initialization with zero rows.

The demo extension contains
`SeedPagesQueueAfterInitialization`, an event listener that checks the official
result and seeds visible `pages` rows through TYPO3 DBAL only when:

- the requested configuration is `pages`
- the official initializer returned without queue rows
- the queue is still empty

The setup command also has a direct six-page demo indexing fallback if the beta
queue worker remains at zero progress. This is compatibility code for the demo,
not a replacement for upstream EXT:solr fixes. Re-test and remove it after a
stable PostgreSQL-safe EXT:solr 14 release.

Before blaming the initializer, confirm pages are visible, not deleted, and
have `no_search = 0`. The `/search` result page itself intentionally uses
`no_search = 1`.

## Benchmarks

Warm local results:

| Operation | Runs | Median | p95 / max |
|---|---:|---:|---:|
| Direct Solr query | 50 | 3.1 ms | 7.0 ms p95 |
| Update + commit | 20 | 34.3 ms | 38.1 ms p95, 60.5 ms max |
| TYPO3 rebuild of six pages | 10 | 1.108s | 1.550s max |
| Complete TYPO3 search page | 30 | 27.2 ms | 28.8 ms p95, 106 ms max |

Verdict: normal queries, updates, and small batches are fast enough. Cold
service activation and durability are the material risks.

The protected endpoint also has `action=benchmark`. It creates synthetic
documents, measures add/update/search, and deletes them afterward. Use it only
with `CRON_SECRET`; it writes to the configured core.

## Long-Running Reindexing

A full site reindex can take hours. Do not run it as one Vercel request.

Use this architecture:

1. Initialize the EXT:solr queue once.
2. Process a bounded number of items per Scheduler invocation.
3. Make the operation resumable and idempotent.
4. Monitor remaining, failed, and indexed queue counts.
5. For a large initial index, run `vendor/bin/typo3` from an always-on worker,
   CI job, VM, or the Solr hosting environment against the same SQL database and
   external Solr endpoint.

The internal Vercel demo service is not a target for a multi-hour reindex
because index state can disappear or split across instances.

See [Long-running jobs](long-running-jobs.md).

## Is There Any Durable Solr Storage On Vercel?

Not through the products used here:

- **Vercel Blob:** durable object storage, not a mounted POSIX/Lucene volume
- **S3/R2:** same limitation; object APIs do not implement Lucene filesystem
  locking, atomic rename, mmap, and low-latency random I/O semantics
- **Container Registry:** stores immutable image layers, not runtime writes
- **SQL/Redis:** useful TYPO3 services, not a Solr index filesystem
- **Function `/tmp`:** writable but local, ephemeral, and not shared

Embedding a prebuilt index in an image can publish a read-only snapshot, and
self-seeding can rebuild a tiny demo. Neither preserves live editor updates.

A durable Vercel-native production solution would require a supported
persistent service volume with backup/restore and predictable attachment to the
Solr instance. Until Vercel offers that, external managed Solr is the correct
solution.

## Troubleshooting

### Backend Says Unable To Contact Solr

Check the protected probe first. Then check Vercel logs for:

- service `startup` without a later `ready`
- `500 Starting...` beyond the 20-second proxy or 25-second renderer window
- nginx `502`/`503`
- wrong core name
- external TLS/authentication failures

Structured `solr-search`, `solr-proxy`, and warm-up logs include retry and
connection counts. Several attempts with one connection are expected during a
cold activation. Several new connections indicate that the platform or upstream
closed the connection and may have selected more than one instance.

Verify that the Solr service binding or direct endpoint is present, that
`TYPO3_SOLR_ENABLED` is not `0`, and redeploy after any environment change.

### URL Contains `/solr/solr/core_en/`

The configured base path includes `/solr` twice. `TYPO3_SOLR_URL` may include
the complete `/solr/core_en` URL; the config writer normalizes it. Do not also
set `TYPO3_SOLR_PATH=/solr/` unless the provider actually has an additional
prefix.

The generated TYPO3 site connection should normally have base path `/`. The
private service proxy maps language IDs 0-4 to `core_en`, `core_de`, `core_es`,
`core_zh`, and `core_hu` respectively.

### Search Page Is Missing

Call `action=setup`. The command is idempotent and creates or repairs both the
page and `vercel_solr_demo_results` content element.

### Search Returns Zero During A Deploy

Current service images do not advertise readiness before the startup seed is
committed. If zero results still appear, inspect the structured `ready` record
for `demo_documents: 30`, `demo_cores: 5`, and run the protected select probe.
Do not assume a manual warm-up reached the same service instance a visitor
receives.

### Scheduler Returns A Safe Skip

That is expected for the internal non-durable service. Runtime indexing is
disabled by default. External Solr runs the real Scheduler task.

## Security And Operations

- Keep external Solr behind TLS and authentication.
- Do not expose the internal service or app retry proxy through public rewrites.
- Protect setup, benchmark, diagnostics, and Scheduler with `CRON_SECRET`.
- Use least-privilege read/write Solr credentials where the provider supports
  separate roles.
- Back up and restore-test the external index or maintain a tested complete
  reindex procedure.
- Monitor core health, query errors, queue failures, disk growth, heap, and
  commit latency.
- Put Solr, Vercel compute, and SQL in nearby regions.

## References

- [EXT:solr version matrix](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html)
- [EXT:solr Scheduler](https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Backend/Scheduler.html)
- [Apache Solr Reference Guide](https://solr.apache.org/guide/solr/latest/)
- [Vercel Services](https://vercel.com/docs/services)
- [Vercel Container Registry](https://vercel.com/docs/container-registry)
