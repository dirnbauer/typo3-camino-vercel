.. include:: /Includes.rst.txt

.. _adr-008:

=================================================
ADR-008: Enforce environment-backed security
=================================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

Concurrent, replaceable instances must agree on cryptographic and trust
settings. TYPO3 also distinguishes authenticated System modules from standalone
Install Tool access. A generated per-instance key, loose host pattern, public
diagnostic route, or unconditional ``?__typo3_install`` switch would undermine
the deployment boundary.

History evidence
================

- ``e23353d`` (2026-07-08) hardened security and the serverless runtime.
- ``70a2c5b`` (2026-07-08) hardened TYPO3 runtime locks.
- ``e3f998d`` (2026-07-15) resolved the configured maintainer from SQL.
- ``3d23960`` (2026-07-15) allowed authenticated TYPO3 System modules.
- ``907f7fb`` (2026-07-15) kept standalone Install Tool access private.

Decision
========

Require one stable ``TYPO3_ENCRYPTION_KEY`` on Vercel and fail startup when it
is absent. Build trusted-host configuration from an anchored operator pattern,
derive proxy HTTPS behavior from Vercel headers, and keep runtime locks under
the writable temporary tree.

Resolve the setup administrator's real database UID as a system maintainer.
Allow authenticated backend System modules independently of direct Install Tool
access. Standalone ``?__typo3_install`` remains disabled unless both the
explicit enable switch and password configuration are present.

Keep shallow health public and protect provider-specific deep probes,
maintenance, Scheduler, and benchmark actions with ``CRON_SECRET``. Store all
real credentials in environment management, never in repository configuration.

Consequences
============

**Positive:**

- Cryptographic behavior is consistent across instances.
- Public query parameters cannot expose the standalone Install Tool.
- System maintainers retain normal authenticated administration workflows.
- Deep provider details and privileged actions remain protected.

**Negative:**

- Missing or inconsistent secrets deliberately stop or degrade the service.
- Operators must rotate and scope environment variables correctly.
- Temporary direct Install Tool access requires an explicit deployment
  change.

Alternatives considered
=======================

1. **Generate a key at every start:** Rejected because instances would disagree
   on cHash, sessions, and encrypted state.
2. **Enable direct Install Tool access whenever its query parameter is
   present:** Rejected because a public URL must not select a privileged
   application.
3. **Assume the admin user has UID 1:** Rejected because existing databases may
   assign another UID or retain a deleted UID 1 record.
