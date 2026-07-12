# Apache Solr Search

## Decision

The repository supports two Solr modes:

| Mode | Purpose | Index durability |
|---|---|---|
| Internal Vercel Solr service | Demo, experiments, integration tests | No; self-seeded on each instance |
| External Solr 10 endpoint | Real indexing and production search | Provider/volume dependent |

Vercel does not currently provide managed Apache Solr or a persistent mounted
service volume suitable for Solr's live Lucene index. Production Solr should
run on a managed provider or always-on infrastructure with durable storage,
backups, monitoring, and access control.

## Version Set

The Composer lock and service image currently use:

- TYPO3 CMS `14.3.4`
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
- a separate Vercel Container Image service running real Solr 10
- a private service binding from TYPO3 to Solr
- a bounded retry proxy for Vercel service activation responses
- six self-seeded Camino demo documents for the non-durable service
- native EXT:solr autocomplete using `fetch`, `AbortController`, and
  `autoComplete.js`, with Camino styling and no jQuery

## Search Suggestions

The Camino search field uses EXT:solr's official browser-side
`suggest_controller.js` and `autocomplete.min.js`. A small JSON adapter at page
type `7384` queries the configured Solr core and returns the response shape that
the official controller expects. The adapter preserves the localized search
path, requires two characters, caps input at 50 characters, removes punctuation,
deduplicates titles, and limits the top-document list to four records.
The search partial registers both scripts through TYPO3's AssetCollector; this
keeps their delivery coupled to the component and avoids relying on a stale
global TypoScript asset cache after deployment.

The internal demo service is a special case: it contains six immutable,
self-seeded documents, so autocomplete ranks the same six-document catalog in
PHP instead of cold-starting a JVM on every keystroke. Full result pages still
query Apache Solr. When `TYPO3_SOLR_URL` points to external production Solr, the
adapter queries that live index for suggestions as well.

The adapter is deliberate. During implementation, EXT:solr 14.0.0-beta3's
Extbase suggest action returned `RequiredArgumentMissingException` for
`queryString` even when called with the documented request namespace. Reusing
the official non-jQuery UI with a bounded adapter fixed the user-facing feature
without replacing the main EXT:solr search or indexing implementation. Recheck
this adapter when upgrading to a stable EXT:solr 14 release.

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
4. enables only the English `core_en` demo core
5. uses a 256 MB initial and 512 MB maximum Java heap
6. stores writable state below `/tmp/solr-home`

The service-local nginx binds the Vercel port immediately while Java starts.
It exposes internal health paths:

```text
/__health/live
/__health/ready
```

Readiness returns success only after `core_en` answers a query and all six demo
documents have been committed and counted. Until then, every externally bound
Solr path returns `503 starting`; seeding uses the service-local Solr port and
does not pass through that gate. Startup logs contain structured `startup` and
`ready` records with the measured duration and verified document count.

Local image results from the overhaul:

| Metric | Old service | Current service |
|---|---:|---:|
| Docker image size | about 843 MB | about 589 MB |
| Local readiness | 4.13-4.62s | 1.94-3.21s; 2.48s median over 5 starts |

## Why The Demo Self-Seeds

Vercel can start several Solr service instances. Their `/tmp` filesystems are
not shared, so an update can reach one instance and the next select can reach a
fresh instance with zero documents.

The service avoids a broken demo by inserting the same six Camino documents
when every instance becomes ready. This makes static demo search repeatable,
but it does not make editor-driven runtime indexing durable.

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

The Pro warm-up cron pings Solr every three minutes, but it is not an instance
reservation. After the schedule had run for about 13 hours, three consecutive
cron invocations still found Solr cold: Solr took 14.553, 16.080, and 16.989
seconds with 6-7 attempts. The cron remains useful for the TYPO3 app, but the
reliable demo behavior comes from bounded waiting and per-instance self-seeding,
not from assuming Solr stays resident.

The final production acceptance on 2026-07-11 warmed only the TYPO3 application
first, then requested the search page while Solr was cold. The first search
returned HTTP 200 with all six documents in 16.36 seconds and showed neither a
warming message nor an empty-result state. Its immediate repeat took 0.96
seconds. Runtime telemetry recorded nine attempts and nine actual connections,
which confirms that cURL handle reuse does not guarantee binding affinity. The
correctness guarantee is the `503 starting` readiness gate plus the exact
six-document seed check.

The final cleanup also found that the custom entrypoint inherited
`LOG4J_PROPS=/var/solr/log4j2.xml` without running the official initializer that
creates that file. The service now selects Solr's bundled production
configuration explicitly. A custom WARN-only replacement was tested and
rejected after one 41.9-second start and one pre-bind exit. See
[Final cleanup audit](final-cleanup.md) for the complete problem/solution table.

## Enable The Internal Demo

Set production environment variables:

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
`TYPO3_SOLR_SERVICE_URL` from the service binding.

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
```

The expected demo result count is six. A genuinely cold first search may take
roughly 15-20 seconds, but it should wait for results rather than first showing
the intermediate warming state.

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

DDEV uses PHP 8.4 and the matching Solr 10 configset:

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

Verify `TYPO3_SOLR_ENABLED=1` and redeploy after any environment change.

### URL Contains `/solr/solr/core_en/`

The configured base path includes `/solr` twice. `TYPO3_SOLR_URL` may include
the complete `/solr/core_en` URL; the config writer normalizes it. Do not also
set `TYPO3_SOLR_PATH=/solr/` unless the provider actually has an additional
prefix.

The generated TYPO3 site connection should normally have base path `/` and
language core `core_en` for the private service proxy.

### Search Page Is Missing

Call `action=setup`. The command is idempotent and creates or repairs both the
page and `vercel_solr_demo_results` content element.

### Search Returns Zero During A Deploy

Current service images do not advertise readiness before the startup seed is
committed. If zero results still appear, inspect the structured `ready` record
for `demo_documents: 6` and run the protected select probe. Do not assume the
Pro warm-up reached the same service instance a visitor receives.

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
- [Vercel Container Images](https://vercel.com/docs/functions/container-images)
- [Vercel Container Registry](https://vercel.com/docs/container-registry)
