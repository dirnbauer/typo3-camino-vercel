# Documentation

This directory separates the easy installation path from the implementation
and operational detail. Start with the first group and use the remaining guides
when the deployment becomes more than a disposable demo.

## Start Here

| Guide | Purpose |
|---|---|
| [Choose one of two setups](deployment-profiles.md) | One-click evaluation versus professional hosting |
| [Quickstart](quickstart.md) | One-click and first durable deployment |
| [Configuration](configuration.md) | Canonical environment and profile reference |
| [Vercel deployment](vercel.md) | Install steps, CLI, regions, and open platform requests |
| [Costs](costs.md) | Hobby, Pro, databases, Blob, Redis, and Solr cost shape |
| [Free demo](free-demo.md) | Exactly what can remain free and what is temporary |

## Durable State

| Guide | Purpose |
|---|---|
| [Database](database.md) | PostgreSQL/MySQL setup and stable backend sessions |
| [Backend login](backend-login.md) | Credentials, sessions, and login troubleshooting |
| [Object storage](object-storage.md) | Blob and S3/R2 durability model |
| [Vercel Blob FAL](vercel-blob-fal-driver.md) | Custom driver, large uploads, OIDC, fallback, and operations |
| [Visual Editor and translations](visual-editor-and-translations.md) | Inline editing, language setup, strict translations, and demo video |
| [Redis](redis-cache.md) | Shared TYPO3 cache, setup, value, and limits |

## Runtime And Performance

| Guide | Purpose |
|---|---|
| [Performance](performance.md) | Cold-start solution, benchmarks, cost, and monitoring |
| [Serverless runtime](serverless-runtime.md) | Writable paths and stateless architecture |
| [Scheduler](scheduler.md) | Vercel Cron and TYPO3 Scheduler |
| [Long-running jobs](long-running-jobs.md) | Batching and external worker architecture |
| [Solr](solr.md) | Demo service, managed production Solr, indexing, and tests |

## Production Review

| Guide | Purpose |
|---|---|
| [Production hardening](production-hardening.md) | Required services and safe settings |
| [Security](security.md) | Secrets, firewall, trusted hosts, and health endpoints |
| [GDPR](gdpr.md) | Privacy and data-processing checklist |
| [Limitations](limitations.md) | Unsupported or conditional behavior |
| [Operations checklist](operations-checklist.md) | Go-live and recurring checks |

## Project Reference

| Guide | Purpose |
|---|---|
| [TYPO3 packages](typo3-packages.md) | Included CMS and extension packages |
| [Template submission](vercel-template-submission.md) | Vercel Marketplace/template metadata |
| [Architecture decisions](../Documentation/Adr/Index.rst) | Decisions reconstructed from Git history and current implementation |

## Configuration Profiles

- `vercel.json` is the one-click profile. It deploys only TYPO3, with no Solr
  service and no cron jobs; eligible SQLite demo pages use automatic edge cache.
- `vercel.pro.json` is the production-latency profile. It warms frontend,
  backend, DB, Redis, and Solr every minute and runs Scheduler every 15
  minutes.
- Pushes to `main` deploy the Pro profile through the CI `deploy` job
  (requires the `VERCEL_TOKEN` secret); `scripts/deploy-pro.sh` remains the
  manual path.

## Documentation Rules

- Never put passwords, database URLs, Blob tokens, or `CRON_SECRET` in examples
  with real values.
- Treat SQLite and the internal Solr service as disposable runtime state.
- Treat Vercel Blob and S3/R2 as file storage, not SQL or a mounted Solr volume.
- Recheck current Vercel plan limits before quoting costs or cron frequency.
- Record cold and warm performance separately and include date, region, and
  deployment SHA for reproducible comparisons.
