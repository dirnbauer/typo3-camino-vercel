# Vercel Product Manager Summary

Date: 2026-07-06

Audience: Vercel product manager or developer-relations reviewer evaluating
whether TYPO3 works well on Vercel Container Images.

## Executive Summary

TYPO3 14.3 with the Camino distribution can run on Vercel Container Images as a
PHP 8.4 Apache container. The public demo now has a durable database, Vercel
Blob-backed uploads, and Vercel Pro/performance CPU in `fra1` Frankfurt.

The result is good for a demo and technically interesting for CMS workloads:
warm frontend and backend requests are fast. The remaining weak point is cold
start behavior. A first request after inactivity or shortly after deploy can
still take about 10 seconds or more.

Short answer to "is it fast now?":

- Warm: yes, fast enough for a TYPO3 demo.
- Cold: not fully; Vercel Container Image cold starts are still visible.
- Production conclusion: viable for demos, prototypes, and selected low/medium
  traffic sites, but serious TYPO3 production use needs clear cold-start,
  database, and file-storage guidance.

## What Was Good

- Container Images made it possible to run a normal PHP/Apache TYPO3 stack
  without rewriting TYPO3 as a serverless application.
- Vercel CLI, deployment inspection, logs, aliases, promotion, and Project API
  were enough to iterate quickly.
- The Deploy Button can create a Vercel Blob store, which is a strong product
  fit for CMS uploads when the CMS has a storage driver.
- Vercel Blob is simpler for all-Vercel demos than asking users to create
  Cloudflare R2 or S3 credentials.
- The Project API allowed the public demo to move from standard CPU to the
  performance CPU class.
- Region pinning to `fra1` is a good fit for European TYPO3 demos when the
  database is also nearby.
- Vercel Firewall, Cron, Blob, encrypted env vars, and marketplace database
  integrations cover most of the operational pieces a CMS needs.

## What Was Coded

This repository now contains a working TYPO3-on-Vercel starter:

- PHP 8.4 Apache Vercel Container Image for TYPO3 14.3 and Camino.
- Automatic TYPO3 bootstrap for first deploys.
- Seeded SQLite demo database for one-click smoke tests.
- Durable external database support through `DATABASE_URL`.
- Serverless runtime filesystem mapping to `/tmp` for `var`,
  `public/fileadmin`, `public/typo3temp`, upload temp files, PHP sessions, and
  image-processing temp files.
- Vercel Blob TYPO3 FAL driver: `vercel_blob`.
- Existing S3-compatible TYPO3 FAL driver kept as `vercel_s3`.
- Object-storage setup script that creates/updates TYPO3 storage records,
  creates upload/processing folders, and can fail startup when storage is
  misconfigured.
- Production object-storage mode for uploaded files and processed derivatives.
- TYPO3 cache defaults suitable for Vercel: runtime-local file caches, OPcache,
  and optional edge HTML cache for anonymous public pages.
- GraphicsMagick and Ghostscript support for TYPO3 image processing.
- Vercel Scheduler/Cron-compatible endpoint for TYPO3 Scheduler tasks.
- Documentation for free demo mode, durable database setup, object storage,
  backend login, performance, security, GDPR, scheduler, and limitations.

## What Was Slow

Initial state:

- SQLite demo mode caused backend login instability because sessions were stored
  in a non-shared runtime SQLite file.
- Uploaded files and generated derivatives were not durable until object
  storage was added.
- Cold backend and frontend requests could take about 10-13 seconds.
- TYPO3 setup/admin-password work and object-storage setup could run during
  cold starts when they should have been one-shot operations.
- Backend routes cannot safely use the optional Vercel edge HTML cache because
  they depend on cookies, sessions, CSRF tokens, and no-store headers.
- Container builds are still several minutes because the image installs system
  packages, PHP extensions, Composer dependencies, TYPO3, and Camino.

Current state:

- Warm frontend home page `/`: first latest hit 1.33s, then 0.12-0.22s.
- Warm backend login `/typo3/`: 0.23-0.41s.
- Warm backend login preflight `/typo3/ajax/login/preflight`: 0.16-0.25s.
- Earlier post-deploy cold-start spikes still happened: `/` at 12.38s and
  `/typo3/` once at 10.61s.

All latest tested routes returned HTTP `200`.

## What Changed

Runtime and infrastructure:

- Public demo was moved to Vercel Pro/performance CPU.
- Vercel project default region and deploy region were set to `fra1`.
- Durable database was enabled for the public demo.
- Vercel Blob was enabled for production uploads and file derivatives.
- Startup flags were reduced after setup so TYPO3 installation, extension setup,
  and admin-password application do not run on every cold start.

TYPO3 and application setup:

- Blob FAL driver was added for Vercel-native durable uploads.
- S3-compatible FAL driver was retained for Cloudflare R2, S3, MinIO, and
  similar providers.
- TYPO3 runtime paths were made serverless-safe by routing mutable writes to
  `/tmp`.
- Object-storage verification was added so bad storage credentials fail loudly.
- Cache defaults and OPcache settings were tuned for an immutable container.
- Optional anonymous frontend edge caching was documented and kept opt-in.

Documentation:

- The README now says clearly that one-click demos can be free but database
  content is not durable without a real DB.
- The docs explain how to add a durable database, Vercel Blob, S3-compatible
  storage, Scheduler/Cron, security, GDPR considerations, and performance
  caveats.

## What This Proves

- TYPO3 can run on Vercel Container Images without a core TYPO3 fork.
- A stateless CMS setup is possible when the app follows this shape:
  code in the image, content in a real database, uploads in object storage,
  caches in disposable runtime storage or an external cache.
- Vercel Blob can be a credible first-party file backend for CMS demos if the
  CMS has a driver.
- Warm performance is acceptable for a demo and much better after infrastructure
  and startup cleanup.
- Cold starts remain the main blocker for a "feels like normal hosting" PHP CMS
  experience.

## Checklist For Vercel Product

- [ ] Make Container Image cold-start time visible as a separate dashboard
      metric from application response time.
- [ ] Expose Function CPU/memory class clearly in the dashboard, CLI, and
      Project API docs for Container Image projects.
- [ ] Consider a supported `vercel.json` or CLI setting for CPU class so teams
      do not need to use the Project API manually.
- [ ] Offer an "always warm" or minimum instances option for Pro Container
      Images, or document the recommended Cron/keepalive pattern explicitly.
- [ ] Provide official CMS guidance: real database, object storage, disposable
      filesystem, no SQLite for sessions, no local uploads.
- [ ] Improve one-click CMS project creation so a Deploy Button can guide users
      through Blob plus a durable database in one flow.
- [ ] Improve marketplace database failure messages. When Neon/TiDB/Supabase
      setup fails, users need a clear reason and a recovery path.
- [ ] Add stronger region guidance that aligns Function region, database
      region, and object storage location.
- [ ] Improve PHP Container Image build caching or publish guidance/base images
      that avoid recompiling common PHP extensions on every clean build.
- [ ] Document Vercel Blob SDK/server-side usage for non-Node runtimes more
      prominently, including PHP API examples and token handling.

## Checklist For Template Users

- [ ] Use the Deploy Button for a quick smoke test.
- [ ] Accept the Vercel Blob store if you want durable uploads.
- [ ] Add `DATABASE_URL` before relying on backend login or edited content.
- [ ] Put the database in or near the Vercel region, currently `fra1`.
- [ ] After first successful setup, set startup flags back to `0`:
      `TYPO3_AUTO_SETUP`, `TYPO3_BOOTSTRAP_EMPTY_DATABASE`,
      `TYPO3_EXTENSION_SETUP_ON_BOOT`, and
      `TYPO3_ADMIN_PASSWORD_APPLY_ON_BOOT`.
- [ ] Keep `TYPO3_CACHE_BACKEND=file` for the small demo unless a shared cache
      is needed.
- [ ] Enable optional edge HTML cache only for anonymous public pages after
      testing forms, frontend login, personalization, and uncached plugins.
- [ ] Use Vercel Pro/performance CPU if backend warm speed matters.
- [ ] Expect occasional cold-start spikes unless an always-warm strategy is
      available and configured.
- [ ] Do not treat SQLite demo mode as production storage.

## Final Product Takeaway

Vercel Container Images are close to being a credible modern hosting path for a
traditional PHP CMS. The current TYPO3 starter shows that the integration can be
made durable and reasonably fast when it uses Vercel Blob, a real database, and
performance CPU.

The product gap is not "can it run?" It can. The gap is making the durable CMS
path obvious and making cold-start behavior easier to see, explain, and control.
