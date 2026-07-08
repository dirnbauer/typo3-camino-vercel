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
TYPO3_SOLR_INCLUDE_STYLESHEETS=1
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
TYPO3_SOLR_PATH=/solr/
TYPO3_SOLR_CORE=core_en
TYPO3_SOLR_USERNAME=<optional-user>
TYPO3_SOLR_PASSWORD=<optional-password>
```

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

## What The Script Writes

`scripts/apply-solr-config.php` updates `config/sites/<site>/config.yaml` with:

- site set dependency `apache-solr-for-typo3/solr`
- optional site set dependency `apache-solr-for-typo3/solr-stylesheets`
- read Solr connection settings
- optional write Solr connection settings
- per-language `solr_core_read`
- optional absolute `base` when `TYPO3_SOLR_SITE_BASE` is set

It does not store secrets in Git. The generated production config happens at
container boot from Vercel environment variables.

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
  php scripts/apply-solr-config.php
```

Run TYPO3 extension setup:

```bash
ddev exec vendor/bin/typo3 extension:setup --no-interaction
ddev exec vendor/bin/typo3 cache:flush
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
- Vercel Cron on paid plans when the cadence fits
- an external HTTPS cron service for more frequent indexing

See [scheduler.md](scheduler.md).

## Can Solr Run As A Vercel Container?

Technically, Vercel can run Dockerfile-based HTTP services. That does not make
Solr a good production service there by default.

Solr needs durable index state in `/var/solr`, predictable startup, private
networking or strong access control, monitoring, backups, and upgrade handling.
A disposable Solr container can be useful for experiments, but it is not the
same as managed Solr.

This repo therefore keeps the Vercel Solr container example outside the main
deployment config:

```text
examples/vercel-solr-service/
```

Do not copy that example into production without solving durability and access
control first.

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
- no guarantee that EXT:solr beta is acceptable for every production project

## References

- EXT:solr version matrix: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Appendix/VersionMatrix.html
- EXT:solr site sets: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Configuration/SiteSets.html
- EXT:solr 14 release notes: https://docs.typo3.org/p/apache-solr-for-typo3/solr/main/en-us/Releases/solr-release-14-0.html
- EXT:solr Packagist package: https://packagist.org/packages/apache-solr-for-typo3/solr
- Scheduler and cron in this repo: scheduler.md
