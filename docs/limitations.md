# Limitations

## Stateless Runtime

Vercel container runtime storage is not a durable TYPO3 volume. Anything written
at runtime can disappear across cold starts, redeploys, or scaling events.

Impact:

- SQLite is demo-only.
- `fileadmin` is copied to `/tmp` at container start and uploads need external
  storage.
- generated cache files should be treated as disposable.
- logs should go to Vercel logs or an external log drain for retention.

## Cron

No Linux cron daemon is expected to run inside the container. Use Vercel Cron or
an external cron caller.

## Long-Running Jobs

Avoid heavy TYPO3 tasks inside request/cron invocations. Split work into small,
idempotent tasks or use a separate worker platform.

## Database Setup

The automatic first-boot setup is intentionally simple. It works best with
standard Postgres/MySQL connection strings. Some MySQL providers require custom
TLS CA handling during setup; test those providers before promising one-click
production deployment.

## Vercel Blob

Vercel Blob is not wired into TYPO3 FAL in this starter. A production Blob
integration needs a TYPO3 FAL driver or bridge service. Until then, use
S3-compatible object storage with a compatible TYPO3 storage driver, or keep the
demo read-only.

## Marketplace Status

This repository is prepared as a Vercel template candidate, but it has not been
submitted to the Vercel Marketplace.

## TYPO3 Introduction Package

The old `typo3/cms-introduction` package currently does not target TYPO3 14.
This starter uses `typo3/theme-camino`, which is the TYPO3 14 Camino
distribution.
