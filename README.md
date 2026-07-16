# TYPO3 Camino on Vercel

[![Deploy with Vercel](https://vercel.com/button)](https://vercel.com/new/clone?repository-url=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel&project-name=typo3-camino-vercel&repository-name=typo3-camino-vercel&demo-title=TYPO3+Camino+on+Vercel&demo-description=Community+Vercel+container+starter+for+TYPO3+14.3+using+the+TYPO3+Camino+distribution.+Not+an+official+TYPO3+package.&demo-url=https%3A%2F%2Ftypo3-camino-vercel.vercel.app&demo-image=https%3A%2F%2Ftypo3-camino-vercel.vercel.app%2Ftemplate-preview.png&from=templates&env=TYPO3_SETUP_ADMIN_USERNAME%2CTYPO3_SETUP_ADMIN_PASSWORD%2CTYPO3_ENCRYPTION_KEY&envDefaults=%7B%22TYPO3_SETUP_ADMIN_USERNAME%22%3A%22admin%22%7D&envDescription=Choose+a+backend+username%2C+set+a+strong+random+backend+password%2C+and+paste+a+stable+96-character+hex+TYPO3+encryption+key.+The+Deploy+Button+creates+a+public+Vercel+Blob+store+for+durable+uploaded+files.+Add+a+real+database+later+for+stable+backend+login+and+durable+content.&envLink=https%3A%2F%2Fgithub.com%2Fdirnbauer%2Ftypo3-camino-vercel%2Fblob%2Fmain%2Fdocs%2Fquickstart.md&stores=%5B%7B%22type%22%3A%22blob%22%2C%22access%22%3A%22public%22%7D%5D)

Community starter for running TYPO3 14.3 with the official Camino distribution
as a PHP 8.5 nginx/PHP-FPM container on Vercel Services. It is not an official
TYPO3 package, and Vercel currently labels Services as Beta.

Live demo: [typo3-camino-vercel.vercel.app](https://typo3-camino-vercel.vercel.app)

## Architecture Boundary

Vercel runs disposable application compute. State that must survive a restart
or exist consistently across instances belongs outside the container:

| Concern | Evaluation profile | Durable profile |
|---|---|---|
| Database | Seeded SQLite copied to `/tmp` | PostgreSQL or MySQL-compatible service |
| Files | Bundled Camino assets plus optional Blob | Vercel Blob or S3-compatible storage |
| Cache | Local files | Local files or shared Redis |
| Search | Not deployed | Managed Solr 10; bundled service is demo-only |
| Jobs | None | Protected, bounded Vercel Cron requests |

The SQLite database, local `var/`, local uploads, and the bundled Solr index are
ephemeral. Use the one-click profile only for evaluation. Connect durable SQL
and object storage before editors create content that matters.

## One-Click Evaluation

1. Click **Deploy with Vercel** and keep the public Blob store selected.
2. Provide unique values for:

   ```dotenv
   TYPO3_SETUP_ADMIN_USERNAME=admin
   TYPO3_SETUP_ADMIN_PASSWORD=<long-random-password>
   TYPO3_ENCRYPTION_KEY=<96-hex-characters>
   ```

3. Generate the encryption key with `openssl rand -hex 48`.
4. Wait for the deployment to become **Ready**, then open `/typo3/`.

There is no shared default password. Store the password and encryption key in a
password manager. The one-click database and its backend sessions can disappear
when the container is replaced or requests reach different instances.

See the [quickstart](docs/quickstart.md) and
[free-demo boundaries](docs/free-demo.md).

## Durable Deployment

At minimum, configure a database URL near the Vercel region:

```dotenv
DATABASE_URL=postgresql://user:password@host/database?sslmode=require
TYPO3_AUTO_SETUP=1
TYPO3_BOOTSTRAP_EMPTY_DATABASE=1
```

Use the two setup flags for the first deployment of a new empty database only.
After the site works, set both to `0` and redeploy. MySQL-compatible URLs are
also supported.

Uploaded files use the included `vercel_blob` or `vercel_s3` TYPO3 FAL driver.
The normal TYPO3 upload route stays below 4 MB because the platform accepts at
most 4.5 MB per request. The authenticated **Media > Large upload** flow sends
larger files directly from the browser to Vercel Blob.

For production, review:

- [deployment profiles](docs/deployment-profiles.md)
- [canonical configuration reference](docs/configuration.md)
- [database setup](docs/database.md)
- [object storage](docs/object-storage.md)
- [production hardening](docs/production-hardening.md)
- [operations checklist](docs/operations-checklist.md)

## Deployment Profiles

`vercel.json` is the Hobby-compatible evaluation profile: one application
service, no cron, and no Solr service.

`vercel.pro.json` adds the private demonstration Solr service and registers a
three-minute warm-up plus a 15-minute Scheduler invocation. Deploy it with:

```bash
VERCEL_SCOPE=your-team scripts/deploy-pro.sh
```

Git-based deployments read `vercel.json`, so run the script after a production
push when the Pro profile is intended. Set a strong `CRON_SECRET` first and
verify the registered schedules after deployment.

The warmer reduces ordinary cold-start exposure but does not reserve an
instance. Use always-on TYPO3 and managed Solr infrastructure when predictable
first-request latency is contractual.

## Included Integrations

- German, Spanish, Simplified Chinese, and Hungarian Camino content with
  strict (no-fallback) translations
- `friendsoftypo3/visual-editor` inline editing
- Vercel Blob and S3-compatible FAL storage
- direct browser-to-Blob large uploads
- optional Redis-backed TYPO3 caches
- EXT:solr 14 beta with Solr 10 configuration
- protected warm-up, Scheduler, maintenance, and deep-health endpoints
- Vercel-aware edge-cache safety and invalidation

The internal Solr service self-seeds a small multilingual index after each
start. Its Lucene index is not durable and must not be treated as production
search storage.

## Local Development

DDEV provides PHP 8.5, MariaDB, and Solr 10:

```bash
ddev start
ddev composer install
ddev solrctl apply
ddev exec vendor/bin/typo3 webconsulting:solr-demo:setup --index --scheduler-task
```

Docker Compose provides a smaller application-and-MariaDB smoke environment:

```bash
docker compose up --build
```

TYPO3's Composer installer generates `public/index.php`. Composer hooks in this
project restore the hardened entry point and environment-backed settings from
`Build/ProjectFiles/`; the lint suite verifies that both copies stay identical.

## Verification

Install dependencies, build the tracked browser bundle, and run all checks:

```bash
composer install
npm ci
npm run build:blob-upload
Build/Scripts/runTests.sh -s all
Build/Scripts/runTests.sh -s containers
```

The `all` selector validates every tracked JSON, YAML, XML, and XLIFF file,
checks immutable generated files, runs PHP syntax checks, PHPStan, Composer
validation, and the PHPUnit suite. `containers` builds both runtime images.

Available selectors are `all`, `lint`, `phpstan`, `unit`, and `containers`.

## Documentation

Start with the [documentation index](docs/README.md). The operational guides
cover Vercel, security, state, caching, search, background work, limitations,
and costs. Architecture decisions and their Git-history evidence are recorded
in the [ADR chapter](Documentation/Adr/Index.rst).

Platform limits and product status can change. Recheck the official
[Services](https://vercel.com/docs/services),
[Docker deployments](https://vercel.com/kb/guide/does-vercel-support-docker-deployments),
[Cron usage](https://vercel.com/docs/cron-jobs/usage-and-pricing),
[Function limits](https://vercel.com/docs/functions/limitations), and
[Blob](https://vercel.com/docs/vercel-blob) documentation before production
decisions.
