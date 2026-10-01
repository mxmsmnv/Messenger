# Changelog

All notable changes to Messenger are documented in this file.

## [1.1.0] - 2026-10-01

### Added

- Added permission-gated administrative broadcasts for all eligible members or a selected ProcessWire role.
- Allowed `messenger-admin` users to enter the Process workspace without also granting moderation access; individual sections retain their own permission checks.
- Added audience preview, explicit confirmation, encrypted source storage, recipient snapshots, idempotent batch delivery, progress, cancellation, and lifecycle audit records.
- Added the responsive **Setup → Messenger → Broadcasts** workspace and broadcast detail view.
- Added a guided audience → message → review workflow, live role visibility and character count, stale-preview protection, and explicit zero-recipient handling.
- Added `broadcasts` dry-run and execution modes to the local maintenance CLI.
- Added public broadcast APIs and a real ProcessWire integration test covering encryption, delivery, replay safety, and authorization.

## [1.0.2] - 2026-09-30

### Fixed

- Render the conversation thread as a semantic section instead of a nested
  `main`, allowing the consuming page shell to remain the single main landmark
  across desktop, tablet, and mobile layouts.

## [1.0.1] - 2026-09-26

### Fixed

- Made the encryption-at-rest migration use an immediate write transaction on
  SQLite and omit its unsupported `FOR UPDATE` clauses there.
- Retained row-level migration locks on MySQL and PostgreSQL.

## [1.0.0] - 2026-09-19

First public release.

### Added

- Private one-to-one conversations between ProcessWire users, including Message Requests, accept/decline flows, unread counts, archive, mute, per-conversation email preferences, edit/delete windows, and hide-for-self controls.
- Idempotent sends, cursor pagination, recipient and bounded encrypted-message search, abuse limits, directional blocks, reports with preserved evidence, temporary restrictions, and audited moderation decisions.
- Same-origin, session-authenticated REST API with CSRF validation, private/no-store responses, explicit versioning, and rate limiting.
- Authenticated encryption at rest for message and report content using libsodium XChaCha20-Poly1305 with a dedicated configuration secret or ProcessWire `tableSalt` fallback.
- Responsive frontend application with LQRS colors and adapters for semantic styles, Designsystemet, UIkit, Bootstrap, Tailwind CSS, and custom mappings.
- ProcessWire administration dashboard, report queue, restriction management, permission-separated evidence access, and responsive configuration fieldsets.
- Optional notification outbox with selectable WireMail provider and a preview-first local CLI worker.
- Retain-on-uninstall data policy, public integration documentation, examples, tests, sponsorship metadata, and MIT license.
