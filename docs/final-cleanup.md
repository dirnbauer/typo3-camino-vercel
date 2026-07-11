# Final Cleanup Audit

This audit records the last production issues found on 2026-07-11, how they
were diagnosed, what was changed, and what remains a Vercel platform limit. It
is intentionally separate from the setup guides so operators can distinguish
implemented fixes from rejected experiments.

## Problems And Resolutions

| Problem | Evidence | Resolution | Final status |
|---|---|---|---|
| Solr answered before its demo index was usable | A cold search returned HTTP 200 in 20.583s with zero results; the immediate repeat returned all six in 0.796s | nginx now returns `503 starting` on every bound Solr path until seeding commits and an exact six-document query succeeds | Fixed; cold searches wait and return six results instead of a false empty page |
| Reusing one cURL handle was described as one connection | Production retries reported 9 attempts/9 connections, then 8/8 and 10/10, although one handle was reused | Code comments and documentation now describe handle reuse as transport hygiene, not connection or instance affinity | Fixed wording; correctness relies on bounded retry and readiness, not affinity |
| Three-minute cron did not keep Solr resident | After about 13 hours, three consecutive warm-ups still spent 14.553-16.989s starting Solr | Keep the cron for best-effort warming, but let each request tolerate activation; recommend managed always-on Solr for production | Mitigated, not eliminated |
| Solr printed `Reconfiguration failed: No configuration found` | The official image expected `/var/solr/log4j2.xml`, but the custom Vercel entrypoint bypassed the initializer that creates it | Set `LOG4J_PROPS=/opt/solr/server/resources/log4j2.xml`, the configuration bundled with Solr | Fixed locally and in production; the reconfiguration error disappeared |
| A custom WARN-only Log4j file looked attractive but was not reliable | Its first local start needed 41.9s; a second container exited before binding its port | Reject the custom file and retain Solr's bundled production configuration | Rejected experiment; reliability took priority over quieter logs |
| Solr INFO output can hide later structured lines in grouped Vercel logs | After the logging fix, query logs proved six hits but Vercel search did not always retain the later `demo_documents` line | Keep shallow structured application telemetry and verify both page output and Solr query hits; do not rely on one grouped log record as the only acceptance signal | Remaining observability limitation |
| Browsers requested a missing favicon | Production logs contained `/favicon.ico` HTTP 404 | Add a tracked Camino ICO with 64, 32, and 16px variants | Fixed; production returns HTTP 200, `image/x-icon`, 22,382 bytes |
| Tiny source changes repeatedly triggered full container rebuilds | Vercel reported `Previous build caches not available` for uploads of about 159KB, 22KB, and 2KB; each deployment rebuilt PHP extensions and both images | Keep Docker layers cache-friendly and record the result for Vercel; no repository setting can force Vercel to reuse an unavailable build cache | Unresolved platform/build-pipeline cost |
| Host tests used the wrong PHP version | macOS PHP 8.3.30 could not run PHPUnit 13, which requires PHP 8.4.1+ | Run project validation in DDEV PHP 8.4 | Fixed workflow; 51 tests and 2,296 assertions pass |

## Final Production Evidence

The runtime implementation was accepted on revision `dcd1d13a20e6` in `fra1`
with PHP 8.4.23:

| Check | Result |
|---|---:|
| Shallow application health | HTTP 200 in 5.32s after deployment |
| First search with Solr cold | HTTP 200 in 16.69s; all 6 results; no warming or empty state |
| Immediate search repeat | HTTP 200 in 0.93s; all 6 results |
| Favicon | HTTP 200; `image/x-icon`; 64/32/16px variants |
| Core and language routes | HTTP 200 for frontend, backend login, Visual Editor, route comparison, and all four language roots |
| Visual Editor video range | HTTP 206 with the requested 1,024 bytes |
| Unexpected runtime HTTP errors | None in the checked deployment logs |
| Solr Log4j reconfiguration errors | 0 after the bundled configuration was selected explicitly |

Cold search time remains variable: accepted runs on the same runtime design
were 14.75s, 16.36s, 16.69s, and 19.34s. The important correctness result is
that each successful response contained all six records.

## Final Validation

- DDEV PHP 8.4: 51 tests, 2,296 assertions
- Composer audit: no known locked production dependency advisories
- npm audit: no known advisories
- generated direct-upload JavaScript: reproducible with no Git diff
- Markdown: all 27 repository documentation files checked; local links and fences valid
- application and Solr images: built successfully
- Solr local acceptance: six seeded documents and no Log4j reconfiguration error
- GitHub and GitLab: both receive every final commit before deployment

## Remaining Limits

- Vercel can still cold-start the TYPO3 and Solr containers independently.
- Vercel Cron is not a minimum-instance reservation.
- The internal Solr index is ephemeral and is for demonstrations only.
- Production search still belongs on managed Solr or always-on infrastructure
  with durable storage, backups, monitoring, and access control.
- A valid Log4j configuration removes the startup error but produces verbose
  INFO logs; Vercel's grouped log retention may omit later lines.
- Vercel build-cache availability is outside this repository's control.

## Operator Rule

Do not accept a deployment only because Vercel says **Ready**. Confirm the Git
revision, health response, cron schedules, favicon/media responses, first and
warm search results, runtime errors, CI, and both remote hashes.
