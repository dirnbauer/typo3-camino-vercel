# Configuration Reference

This page is the canonical operator-facing map of the repository's environment
configuration. Copy from `.env.example`; never commit real credentials.

Values described as one-shot must be reset after the successful deployment.
Advanced retry, timeout, and driver-tuning variables remain documented beside
their implementation in the focused database, storage, Redis, and Solr guides.

## Required On Vercel

| Variable | Purpose |
|---|---|
| `TYPO3_SETUP_ADMIN_USERNAME` | Backend account created during initial setup. |
| `TYPO3_SETUP_ADMIN_PASSWORD` | Strong initial backend password. |
| `TYPO3_ENCRYPTION_KEY` | Stable 96-character hexadecimal key shared by every instance. |
| `TYPO3_TRUSTED_HOSTS_PATTERN` | Anchored allow-list for the real site hostnames. |

The container refuses a Vercel startup without `TYPO3_ENCRYPTION_KEY`. Generate
one with `openssl rand -hex 48`. The default trusted-host expression supports
Vercel preview domains; production sites should narrow it to their real domains.

## Initial Setup And Upgrades

| Variable | Default | Lifecycle |
|---|---:|---|
| `TYPO3_AUTO_SETUP` | `0` | Set to `1` only while provisioning a fresh database. |
| `TYPO3_BOOTSTRAP_EMPTY_DATABASE` | `0` | Allows automatic schema/content setup on an empty durable database. Reset with `TYPO3_AUTO_SETUP`. |
| `TYPO3_SETUP_DISTRIBUTION` | `theme_camino` | Distribution used by initial setup. |
| `TYPO3_SETUP_ADMIN_EMAIL` | `admin@example.com` | Replace before a real deployment. |
| `TYPO3_PROJECT_NAME` | `TYPO3 Camino` | Displayed project name. |
| `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT` | `0` | One deploy only after rotating the configured admin password. |
| `TYPO3_EXTENSION_SETUP_ON_BOOT` | `0` | One deploy only after package changes require extension setup. |
| `TYPO3_SYSTEM_MAINTAINERS` | resolved admin UID | Optional comma-separated backend user UIDs. |

## Database

Prefer one pooled provider URL:

```dotenv
DATABASE_URL=postgresql://user:password@host/database?sslmode=require
```

`postgres://`, `postgresql://`, `mysql://`, and `mariadb://` URLs are parsed.
`POSTGRES_URL` and `MYSQL_URL` are supported provider aliases. Component-style
`TYPO3_DB_DRIVER`, `TYPO3_DB_HOST`, `TYPO3_DB_PORT`, `TYPO3_DB_DBNAME`,
`TYPO3_DB_USERNAME`, `TYPO3_DB_PASSWORD`, and `TYPO3_DB_SSLMODE` are intended
for local or unusual provider setups.

With no database variable, the evaluation profile copies seeded SQLite into
`/tmp`; that state and its sessions are disposable.

## Files

Enable one durable FAL driver:

```dotenv
TYPO3_OBJECT_STORAGE_ENABLED=1
TYPO3_OBJECT_STORAGE_DRIVER=vercel_blob
TYPO3_OBJECT_STORAGE_VERIFY_ON_BOOT=1
```

For Vercel Blob, request OIDC is preferred. `BLOB_READ_WRITE_TOKEN` is the
compatibility fallback. Common options are `TYPO3_BLOB_STORE_ID`,
`TYPO3_BLOB_ACCESS`, `TYPO3_BLOB_PREFIX`,
`TYPO3_BLOB_DIRECT_UPLOAD_MAX_BYTES`, and
`TYPO3_BLOB_DIRECT_UPLOAD_TOKEN_TTL`.

For S3-compatible storage, set `TYPO3_OBJECT_STORAGE_DRIVER=vercel_s3` plus
`TYPO3_S3_BUCKET`, `TYPO3_S3_REGION`, `TYPO3_S3_ACCESS_KEY_ID`, and
`TYPO3_S3_SECRET_ACCESS_KEY`. Add `TYPO3_S3_ENDPOINT`,
`TYPO3_S3_PUBLIC_BASE_URL`, `TYPO3_S3_PREFIX`, or
`TYPO3_S3_PATH_STYLE_ENDPOINT` when the provider requires them.

## Cache And Public Delivery

The safe default is `TYPO3_CACHE_BACKEND=file`. To share selected TYPO3 caches:

```dotenv
TYPO3_CACHE_BACKEND=redis
TYPO3_REDIS_REQUIRED=1
TYPO3_REDIS_URL=rediss://default:password@host:6379/0
TYPO3_REDIS_PREFIX=typo3-camino-vercel:
```

`REDIS_URL` and common Vercel/Upstash component variables are accepted aliases.
TYPO3 requires a TCP/TLS Redis endpoint; REST credentials alone are not enough.

Public edge caching is opt-in for durable sites:

```dotenv
TYPO3_VERCEL_EDGE_CACHE_TTL=600
TYPO3_VERCEL_EDGE_CACHE_STALE_WHILE_REVALIDATE=3600
```

Only anonymous, cookie-free, query-free frontend HTML that TYPO3 marks cacheable
is eligible. Backend, API, form, personalized, and `Set-Cookie` responses remain
private.

## Search And Scheduled Work

For external production Solr:

```dotenv
TYPO3_SOLR_ENABLED=1
TYPO3_SOLR_URL=https://user:password@solr.example/solr/core_en
TYPO3_SOLR_SITE_BASE=https://www.example.com/
TYPO3_SOLR_SITE_IDENTIFIER=camino
```

The Pro profile injects `TYPO3_SOLR_SERVICE_URL` from its private demo-service
binding. Do not set it manually for normal deployments. Site-set, core-routing,
timeout, retry, and bounded-indexing options are listed in `.env.example` and
the [Solr guide](solr.md).

Set `CRON_SECRET` to a long random value before using `vercel.pro.json` or any
maintenance endpoint. Vercel sends it as a Bearer token. Optional
`TYPO3_SCHEDULER_FORCE=1` overrides the safe SQLite skip and should be used only
for deliberate testing.

## Mail, Logging, And Install Tool

The image has no local mail-transfer agent. Configure SMTP when the site sends
mail: `TYPO3_MAIL_TRANSPORT=smtp`, `TYPO3_MAIL_SMTP_SERVER`, optional
`TYPO3_MAIL_SMTP_ENCRYPT`, and provider credentials.

`TYPO3_LOG_PRODUCTION_EXCEPTIONS` and `TYPO3_LOG_TO_PHP_ERROR_LOG` control
production diagnostics. Do not enable verbose exception output to visitors.

Standalone `?__typo3_install` access is disabled by default. Authenticated
system maintainers can still use TYPO3's System modules. Temporarily set
`TYPO3_INSTALL_TOOL_ENABLED=1` and a valid
`TYPO3_INSTALL_TOOL_PASSWORD_HASH` only when direct access is necessary.

## Platform-Owned Variables

The runtime consumes `VERCEL`, `VERCEL_URL`, `VERCEL_PROJECT_PRODUCTION_URL`,
`VERCEL_REGION`, `VERCEL_GIT_COMMIT_SHA`, and `VERCEL_OIDC_TOKEN` when Vercel
injects them. Service bindings inject their declared variables. Operators must
not copy instance-specific values between environments.

## Configuration Files

| File | Authority |
|---|---|
| `.env.example` | Copyable environment examples and safe defaults. |
| `vercel.json` | Hobby-compatible one-service profile. |
| `vercel.pro.json` | Pro profile with demo Solr and protected cron. |
| `config/sites/camino/config.yaml` | TYPO3 site, languages, and site sets. |
| `scripts/typo3-env.php` | Runtime mapping from environment to TYPO3 settings. |
| `Build/ProjectFiles/` | Canonical immutable files restored after Composer. |
| `phpstan.neon.dist` | Static-analysis scope and level. |

Run `Build/Scripts/runTests.sh -s all` after every configuration change.
