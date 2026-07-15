.. include:: /Includes.rst.txt

.. _adr-conventions:

===============
ADR conventions
===============

There is no universal file format for an architecture decision record (ADR).
ISO/IEC/IEEE 42010 standardizes architecture descriptions, but deliberately
does not prescribe one documentation format. This project uses Michael
Nygard's compact ADR anatomy as its baseline and adds evidence needed for
retrospective records.

When to create a record
=======================

Create one ADR for one architecturally significant decision: a choice that
affects system structure, an important quality attribute, a published
interface, a major dependency, or a construction and operating constraint.
Split phased or independently reversible choices into separate records.

Do not use an ADR as a design guide, implementation log, feature description,
or substitute for user and operations documentation.

Required structure
==================

Each record contains these sections:

Status
   ``Proposed``, ``Accepted``, ``Rejected``, ``Deprecated``, or
   ``Superseded by ADR-NNN``. Accepted retrospective records also state their
   recording date.

Context
   The forces, constraints, and problem that made a decision necessary. Keep
   facts separate from the selected solution.

History evidence
   For retrospective records, list the Git commits and dates that establish
   what changed. Verify the resulting claim against the current code and
   configuration. Do not present an inference as a historical fact.

Decision
   State the chosen approach assertively and precisely enough to review future
   changes for conformance.

Consequences
   Record both benefits and costs. Do not assign a numeric score unless a
   documented scoring model and supporting measurements exist.

Alternatives considered
   Name credible alternatives and explain why they were not selected. This is
   optional for a small forward-looking record, but required for this
   retrospective decision log when the evidence supports it.

Lifecycle
=========

Number records monotonically and never reuse a number. A proposed record may
change during review. Once accepted or rejected, preserve it as decision
history. A later decision is a new ADR that links to and supersedes the old
record; the old record remains in the log with its updated status.

Review checklist
================

- The title describes one decision rather than a broad topic.
- Context explains why the decision was needed at that time.
- Decision, evidence, and current implementation do not contradict each other.
- Consequences include material drawbacks and operational limits.
- Alternatives are credible, not straw-man options.
- Security, durability, cost, and failure-mode claims are verifiable.
- The record is concise, factual, standalone, and linked from the index.
- ReStructuredText renders without warnings and keeps lines within 80 columns.

Sources
=======

- `Michael Nygard: Documenting architecture decisions
  <https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions>`__
- `Markdown Architectural Decision Records (MADR)
  <https://adr.github.io/madr/>`__
- `AWS architectural decision record process
  <https://docs.aws.amazon.com/prescriptive-guidance/latest/
  architectural-decision-records/adr-process.html>`__
- `AWS ADR best practices
  <https://docs.aws.amazon.com/prescriptive-guidance/latest/
  architectural-decision-records/best-practices.html>`__
- `Microsoft: Maintain an architecture decision record
  <https://learn.microsoft.com/en-us/azure/well-architected/
  architect-role/architecture-decision-record>`__
- `ISO/IEC/IEEE 42010:2022
  <https://www.iso.org/standard/74393.html>`__
