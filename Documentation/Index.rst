.. include:: /Includes.rst.txt

.. _start:

=======================
TYPO3 Camino on Vercel
=======================

:Repository:
   `dirnbauer/typo3-camino-vercel
   <https://github.com/dirnbauer/typo3-camino-vercel>`__

:Language:
   en

:License:
   The project is licensed under GPL-2.0-or-later.

This documentation chapter records the architectural decisions behind running
TYPO3 Camino on disposable Vercel container Services. Operational setup remains
in the repository's `Markdown documentation
<https://github.com/dirnbauer/typo3-camino-vercel/tree/main/docs>`__.

Architecture
============

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :card-height: 100

   .. card:: Architecture decision records

      Decisions reconstructed from Git history and checked against the current
      implementation.

      .. card-footer:: :ref:`Read the ADR chapter <adr>`
         :button-style: btn btn-primary stretched-link

   .. card:: Operational documentation

      Deployment, configuration, security, storage, performance, and operations
      guides.

      .. card-footer:: `Read on GitHub
         <https://github.com/dirnbauer/typo3-camino-vercel/tree/main/docs>`__
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :maxdepth: 2
   :titlesonly:

   Adr/Index
