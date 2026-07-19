.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

=============================
Architecture decision records
=============================

This chapter documents significant architectural decisions in TYPO3 Camino on
Vercel.
The records were reconstructed after the code and documentation audit from the
repository history between July 2 and July 15, 2026, then checked against the
final implementation.

The chapter follows the project-wide :ref:`adr-conventions`. In particular,
every record covers one significant decision and states both benefits and
tradeoffs without assigning arbitrary numeric scores.

.. card:: ADR conventions

   Read how records are scoped, structured, reviewed, and superseded.

   .. card-footer:: :ref:`Read <adr-conventions>`
      :button-style: btn btn-secondary stretched-link

.. _adr-decision-records:

Decision records
================

Platform foundation
-------------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-001: Disposable Vercel compute

      Run TYPO3 as replaceable compute and keep runtime writes under ``/tmp``.

      .. card-footer:: :ref:`Read <adr-001>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-002: Two deployment profiles

      Keep one-click evaluation separate from the Pro operational profile.

      .. card-footer:: :ref:`Read <adr-002>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-004: Immutable warmed runtime

      Build dependencies and safe TYPO3 release caches into the image.

      .. card-footer:: :ref:`Read <adr-004>`
         :button-style: btn btn-secondary stretched-link

State and delivery
------------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-003: External durable state

      Use SQL and object storage for durable state; keep Redis optional.

      .. card-footer:: :ref:`Read <adr-003>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-005: Conservative edge caching

      Cache only anonymous public HTML after TYPO3 confirms it is safe.

      .. card-footer:: :ref:`Read <adr-005>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-010: Durable processed images

      Keep image derivatives on object storage so cached URLs stay valid.

      .. card-footer:: :ref:`Read <adr-010>`
         :button-style: btn btn-secondary stretched-link

Services and operations
-----------------------

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: ADR-006: Demo-only internal Solr

      Prove integration with ephemeral Solr, but externalize production search.

      .. card-footer:: :ref:`Read <adr-006>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-007: Short protected jobs

      Invoke short maintenance batches through authenticated HTTP endpoints.

      .. card-footer:: :ref:`Read <adr-007>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-008: Environment-backed security

      Keep stable secrets external and direct Install Tool access private.

      .. card-footer:: :ref:`Read <adr-008>`
         :button-style: btn btn-secondary stretched-link

   .. card:: ADR-009: Pro releases from CI

      Deploy the Pro profile automatically after green checks on ``main``.

      .. card-footer:: :ref:`Read <adr-009>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :hidden:

   Conventions
   Adr001DisposableVercelCompute
   Adr002DeploymentProfiles
   Adr003ExternalDurableState
   Adr004ImmutableWarmedRuntime
   Adr005ConservativeEdgeCaching
   Adr006DemoOnlyInternalSolr
   Adr007BoundedProtectedJobs
   Adr008EnvironmentBackedSecurity
   Adr009CiProReleases
   Adr010DurableProcessedImages
