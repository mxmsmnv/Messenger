# Changelog

All notable changes to Messenger are documented in this file.

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
