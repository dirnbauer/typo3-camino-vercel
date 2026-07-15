.. include:: /Includes.rst.txt

.. _adr-004:

=============================================
ADR-004: Build an immutable warmed runtime
=============================================

Status
======

**Accepted.** Retrospectively recorded on 2026-07-15.

Context
=======

Container activation and first TYPO3 bootstrap work dominate cold requests.
Building everything during startup increases latency and creates failure paths.
Copying arbitrary runtime cache state into an image is unsafe because some
artifacts contain environment- or instance-specific information.

History evidence
================

- ``8632539`` (2026-07-05) began Vercel runtime optimization.
- ``c1a1cd6`` (2026-07-06) improved backend cold starts.
- ``4294273`` (2026-07-06) reverted unsafe TYPO3 cache seeding.
- ``5ff0007`` (2026-07-09) overhauled runtime and cold-start handling.
- ``635d5f2`` (2026-07-09) hardened warm-up and the runtime build.

Decision
========

Use a multi-stage Alpine image with nginx and PHP 8.4 FPM. Compile extensions
and install Composer dependencies during the image build. Exclude development
dependencies and build tools from the runtime stage.

Create a deterministic seed database during the build. Warm only the TYPO3
dependency-injection and Fluid template release caches, copy them to an
immutable image location, and restore them into ``/tmp`` during startup.

TYPO3's Composer and setup commands generate project files. Keep canonical
copies of the environment-backed settings and hardened public entry point under
``Build/ProjectFiles``. Composer hooks and the image build restore them and
verify byte identity after every generation step.

Consequences
============

**Positive:**

- Runtime startup avoids Composer and full framework compilation.
- The shipped entry point and settings are reproducible.
- A discarded build stage keeps the runtime smaller.
- Unsafe cache categories are not promoted into the release image.

**Negative:**

- Image builds perform TYPO3 setup and cache warming.
- Canonical generated files exist as templates and runtime copies.
- TYPO3 upgrades must be checked for new generated-file behavior.

Alternatives considered
=======================

1. **Run Composer at container start:** Rejected because it is slow, mutable,
   and network-dependent.
2. **Copy the entire warmed ``var/cache`` tree:** Rejected after history showed
   that environment-specific cache seeding was unsafe.
3. **Accept Composer's stock entry point:** Rejected because it would bypass the
   Vercel runtime preparation and direct Install Tool access policy.
