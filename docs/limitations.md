# Limitations

## Stateless Runtime

Vercel container runtime storage is not a durable TYPO3 volume. Anything written
at runtime can disappear across cold starts, redeploys, or scaling events.

Impact:

- SQLite is demo-only.
- TYPO3 backend login is not stable with SQLite in `/tmp`, because backend
  sessions are database-backed and Vercel can run parallel requests on separate
  instances.
- `fileadmin` is copied to `/tmp` at container start. Uploads are durable only
  when Vercel Blob or S3-compatible object storage is configured.
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

Vercel Blob is wired into TYPO3 FAL through the `vercel_blob` driver. Use a
public Blob store for normal frontend images and downloads. Private Blob stores
need a custom delivery/proxy strategy and are not the default for TYPO3 public
assets.

## Marketplace Status

This repository is prepared as a Vercel template candidate, but it has not been
submitted to the Vercel Marketplace.

## TYPO3 Introduction Package

The old `typo3/cms-introduction` package currently does not target TYPO3 14.
This starter uses `typo3/theme-camino`, which is the TYPO3 14 Camino
distribution.
