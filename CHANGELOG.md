# Changelog

All notable changes to Messenger are documented in this file.

## [1.2.1] - 2026-10-18

### Fixed

- Require a trusted web-server HTTPS indicator for native identity and bearer requests. ProcessWire may infer its HTTPS flag from a client-supplied X-Forwarded-Proto header; that header alone no longer passes the mobile transport check. TLS proxies must configure the HTTPS server environment explicitly.
- Verified fresh installation and member/browser PKCE flows on disposable real ProcessWire/MySQL over locally verified TLS; see NARZAN.md for remaining deployment gates.

## [1.2.0] - 2026-10-18

### Added

- Optional Narzan mobile authorization, disabled by default: exact HTTPS issuer and callback allow-list, browser session/CSRF consent, S256 PKCE, single-use two-minute codes, ten-minute access tokens, rotating thirty-day refresh tokens and connection revocation.
- Device credentials stored only as keyed hashes; refresh reuse revokes the connection. Native requests reject cookie/bearer mixing and never create a browser login session.
- Browser device-management page with CSRF-protected own-device revocation and bounded device metadata.
- Additive `GET sync` opaque revisions for conservative invalidate-and-refetch of cached messages, including edits and deletes older than the message-ID cursor. This is not an incremental event feed.
- Isolated domain, identity, transport and real HTTP fixture checks, without using a consuming site's database.

### Fixed

- Retry recognition precedes new-message pending/rate limits, while actor, membership and recipient safety remain mandatory. Bind each client ID to the original conversation and immutable keyed body fingerprint; conflicting requests return 409.
- Keep hidden-for-self messages out of inbox previews and unread counts.
- Revalidate recipient eligibility outside policy hooks and when sending into existing conversations; fail closed when restriction lookup fails.
- Couple edits, deletes, hides and member state updates to their invalidation audit in transactions. Remove internal request/client fingerprints from edit responses.

### Compatibility

- Browser routes, session authority, JSON envelopes, CSRF token format and existing member endpoints remain supported. A separate mobile endpoint family is opt-in; no native token permits administrative actions.
- Upgrade adds an immutable request fingerprint column and four retained mobile credential/rate tables. Existing ciphertext, associated data, encryption keys and browser sessions are not rewritten.
- Legacy edited/deleted messages without an original request fingerprint reject ambiguous retries rather than inventing a successful match.
- No site deployment, app registration, push delivery or production readiness is implied by this release candidate. See `NARZAN.md` for activation and disposable-site gates.

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
