# Vercel Product Manager Summary

Date: 2026-07-06

Audience: Vercel product manager or developer-relations reviewer evaluating
whether TYPO3 works well on Vercel Container Images.

## One-Page Verdict

TYPO3 14.3 with the Camino distribution can run on Vercel Container Images as a
normal PHP 8.4 Apache application. The public demo now uses a durable database,
Vercel Blob-backed editor uploads, Vercel Pro/performance CPU, and `fra1`
Frankfurt.

The biggest result: warm requests are now fast enough for a demo. The biggest
remaining problem: cold starts still show up clearly.

Current live check against `https://typo3-camino-vercel.vercel.app`:

- All tested routes returned HTTP `200`.
- Frontend `/`: about 0.12-0.25s once warm.
- Backend login `/typo3/`: one cold hit at about 11.1s, then about 0.35-0.40s.
- Login preflight `/typo3/ajax/login/preflight`: about 0.16-0.27s.
- Earlier deploy-time checks saw similar cold spikes: `/` about 12.4s and
  `/typo3/` about 10.6s.

Short answer to "is it fast now?":

- Warm frontend: yes.
- Warm backend login surface: yes, for a demo.
- Cold starts: no, still the visible weakness.
- Durable files: yes, when Vercel Blob is connected.
- Durable content and stable backend login: yes, when a real database is used.

Production conclusion:

- Good fit today: demos, template installs, sales prototypes, public content
  sites with moderate traffic, and projects where occasional first-hit latency
  is acceptable or can be hidden behind cache/keepalive.
- Possible but needs care: real TYPO3 projects with editors, durable uploads,
  and custom extensions, as long as they use a real database, object storage,
  and a clear plan for cold starts.
- Not a clean default yet: business-critical TYPO3 installations that require
  predictable first-hit latency, heavy backend editing, heavy image processing,
  high concurrency, or strict "traditional hosting" behavior.

Technical solution:

- **Vercel-native production profile:** use Pro/Enterprise performance CPU,
  `fra1` or the database-nearest region, a durable SQL database, Vercel Blob or
  S3-compatible FAL storage, runtime-local TYPO3 caches, optional anonymous
  edge HTML cache, and a Pro/external keepalive that warms `/_vercel_keepalive.php`
  and optionally `/typo3/`.
- **Strict production profile:** keep TYPO3 backend/origin on always-on PHP
  infrastructure and use Vercel for public frontend delivery, CDN caching,
  previews, and template/demo deployments.

See [production-hardening.md](production-hardening.md) for the concrete
settings, cron shape, and decision rule.

## Before And After

These are directional numbers from the live demo, not lab-grade benchmarks.

| Area | Before | After | What changed most |
| --- | --- | --- | --- |
| Frontend warm page | roughly sub-second after warmup | about 0.12-0.25s | Better cache/runtime setup; good enough |
| Backend login warm page | inconsistent during early setup | about 0.35-0.40s in latest check | Real DB, startup cleanup, performance CPU |
| Backend login preflight | usable but affected by setup/session issues | about 0.16-0.27s | Real DB and stable runtime config |
| Cold starts | about 10-13s | still about 10-12s when they happen | Not materially solved |
| Backend login reliability | could log out after seconds in SQLite demo mode | stable with durable DB | Real DB was the decisive fix |
| Uploaded files | could disappear with local `fileadmin` | durable with Vercel Blob | Blob FAL driver |
| Build/deploy time | several minutes | still several minutes | Mostly unchanged |

## What Was Most Useful

1. **Real database**

   This was the biggest correctness fix. It fixed backend sessions and content
   durability. It did not magically make cold starts disappear, but it turned
   the backend from "demo can log you out" into "usable backend."

2. **Vercel Blob FAL driver**

   This was the biggest product-fit fix for an all-Vercel CMS demo. TYPO3 needs
   durable FAL storage for uploads and processed files. Blob made editor
   uploads durable without asking every user to configure Cloudflare R2 or S3.
   It was mostly a durability improvement, not a page-speed improvement.

3. **Startup cleanup**

   Turning one-shot setup tasks into real one-shot tasks mattered. Re-applying
   the admin password, running extension setup, or doing storage setup work on
   every cold start is bad for a CMS container. Reducing that work helped warm
   behavior and removed avoidable startup cost, but platform cold starts remain.

4. **Vercel Pro/performance CPU**

   This helped warm PHP work and backend rendering, but it did not eliminate
   cold starts. The right interpretation is "faster once the container is
   running," not "always instant."

5. **`fra1` region pinning**

   Useful for this European demo, especially if the database is also nearby.
   It reduces network latency, but it cannot compensate for a cold container or
   a database/object store in the wrong region.

6. **Serverless filesystem mapping**

   Mapping mutable TYPO3 paths to `/tmp` made the app behave correctly on
   Vercel. It is necessary plumbing, but by itself it is not a speed feature.

## What Did Not Change Much

- **Cold starts:** still the largest remaining issue. CPU class and TYPO3
  cleanup helped warm behavior, but cold starts still measured around 10-12s.
- **Build time:** still several minutes because the image installs system
  packages, PHP extensions, Composer dependencies, TYPO3, and Camino.
- **Backend cacheability:** backend routes still cannot safely be edge-cached
  because they use sessions, cookies, CSRF tokens, and no-store behavior.
- **Blob and durability work:** very important for a CMS, but not expected to
  improve `/typo3/` response time much.
- **Redis:** likely not the first speed fix for a small demo. A remote Redis hop
  can be slower than runtime-local file caches unless many containers need
  shared warm cache state.

## Surprises

- **The highest-effort speed experiment did not help:** pre-seeding TYPO3's
  generated code cache inside the image looked promising, caused runtime `500`
  responses, and was reverted. TYPO3 runtime cache can include
  environment-specific state that is not safe to reuse across Vercel runtime
  starts.
- **The real database fixed login more than speed:** it was essential because
  backend sessions live in the database. It did not remove the cold-start
  problem.
- **Performance CPU improved the warm story, not the cold story:** this is an
  important product-message distinction for PHP CMS users.
- **Vercel Blob was easier than S3 for users, but required TYPO3-specific
  code:** Blob is not S3-compatible, so an actual TYPO3 FAL driver was needed.
- **The WordPress pattern was right:** code in the image, content in a DB,
  uploads in object storage. TYPO3 can follow the same pattern, but needs
  TYPO3-specific setup and docs.

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
  creates upload/processing folders, and fails startup when verification is
  enabled but storage is misconfigured.
- Production object-storage mode for uploaded files and processed derivatives.
- TYPO3 cache defaults suitable for Vercel: runtime-local file caches, OPcache,
  and optional edge HTML cache for anonymous public pages.
- GraphicsMagick and Ghostscript support for TYPO3 image processing.
- Vercel Scheduler/Cron-compatible endpoint for TYPO3 Scheduler tasks.
- Documentation for free demo mode, durable database setup, object storage,
  backend login, performance, security, GDPR, scheduler, and limitations.

## What Was Good About Vercel

- Container Images could run the normal PHP/Apache application model.
- Vercel CLI, logs, inspect, aliases, promotion, and Project API were enough to
  debug and iterate quickly.
- Deploy Button plus Vercel Blob is a good onboarding story for CMS uploads.
- Encrypted environment variables are a good fit for TYPO3 secrets.
- Vercel Firewall, Cron, Blob, and marketplace databases cover most surrounding
  platform needs.
- Region pinning and performance CPU are useful once discovered and configured.

## Product Gaps For Vercel

- Cold-start time needs to be visible as its own metric, separate from app
  response time.
- Container Image projects need clearer CPU/memory controls in the dashboard,
  CLI, and docs.
- A supported `vercel.json` or CLI setting for CPU class would be easier than
  using the Project API.
- Pro Container Images need an explicit always-warm/minimum-instances story, or
  clearer official keepalive guidance.
- One-click CMS setup should guide users through Blob plus a durable database,
  not only Blob.
- Marketplace database failures need clearer recovery messages.
- PHP Container Image guidance should include common extension/base-image
  strategies to reduce multi-minute builds.
- Vercel Blob documentation should include non-Node server examples, especially
  PHP token handling and public URL patterns.
- The missing product feature for strict TYPO3-on-Vercel is a minimum-instances
  or always-warm control for paid Container Image workloads.

## Checklist For Vercel Product

- [ ] Show cold starts and warm responses separately in analytics.
- [ ] Make Container Image CPU/memory class discoverable and configurable.
- [ ] Add first-party CMS deployment guidance: real DB, object storage,
      disposable filesystem, no SQLite sessions, no local uploads.
- [ ] Improve Deploy Button flows for "Blob plus database" templates.
- [ ] Provide an always-warm or minimum-instances option for paid container
      workloads, or document the recommended keepalive tradeoff.
- [ ] Improve region guidance across Function, DB, and object storage.
- [ ] Improve PHP build caching/base-image examples.

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

The product gap is no longer "can a traditional PHP CMS run on Vercel?" It can.
The public TYPO3 demo proves that.

The remaining product challenge is making the durable CMS path obvious and
making cold-start behavior easier to see, explain, and control. Warm TYPO3 on
Vercel is already acceptable for this demo. Cold TYPO3 on Vercel still feels
like a platform problem, not a TYPO3 tuning problem.
