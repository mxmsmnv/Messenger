# Changelog

All notable changes to Messenger are documented by release date, with versions listed newest first within each day. Git history is preserved.

## 2026-10-25

### [1.5.9] — development candidate, not installed

- Add opt-in private Saved messages with one owner/participant, idempotent explicit opening, existing encrypted text/files and account synchronization. Opening preserves hidden history; normal deletion and retention policies remain authoritative.
- Preserve legacy inbox kinds through explicit Saved opt-in. Suppress peer notifications and calls, require private pin scope, and recheck owner-only access for every metadata/history/file/action path. No new schema or production activation.

### [1.5.8] — development candidate, not installed

- Add paginated search of one authorized conversation's encrypted history, without the legacy global search's1,000-message cutoff. Bound each request to500scanned rows and50matches, keep exclusive cursors across empty scans and preserve normal membership/history/visibility checks.
- Add a cancellable per-conversation web search dialog with bounded result pages, older/previous navigation and an authorized jump to the original that preserves the composer. Reject stale replies after cancellation/closure.
- Advertise `history_search` without altering legacy global search or adding a plaintext index, schema migration or production rollout.

## 2026-10-24

### [1.5.7] — development candidate, not installed

- Make reaction/pin change counters timezone-independent under serialized epoch windows; explicitly upgrade existing1.5.6 lock rows through the operator migration.

- Add personal chat pins and separately scoped personal/shared message pins. Keep other members' personal organisation private; shared group pins require current owner/admin rights. Apply original message, group join-floor, hide/delete/expiry and attachment access rules.
- Keep legacy inbox cursors unchanged with an explicit first-page pin prefill. Bound pins and serialize actual state changes per member; exact desired retries do not toggle or consume the change rate. Add tables only through the operator migration.
- Add web pin controls, scoped confirmation, original-message navigation and a protected history window with explicit return to latest and preserved draft. No production migration or store release is included.

### [1.5.6] — development candidate, not installed

- Add encrypted, participant-authorized reactions shared by native and web clients, with six choices, counts/actual authors, exact desired-state retries and explicit removal of only the actor's reaction. Honor hidden/deleted messages, blocks, group membership/history floors and a bounded state-change rate.
- Exclude removed/rejoining group members from old reaction counts and keep forwarding content revisions stable. Add the reaction table only through the separate operator migration; existing1.5.5 forwarding remains compatible without it.
- Refresh browser message revisions without replacing the composer, losing its focus/draft or forcing a non-bottom reader to the latest message. No reaction push, production migration or website rollout is included.

### [1.5.5] — development candidate, not installed

- Add explicit forwarding of one to ten visible messages to an accepted direct/group conversation. Create independent encrypted file/voice copies with a fresh album ID, trusted forwarding marker and no disclosure of the original sender or private conversation.
- Freeze selected content revisions, reject intervening edits, commit batches atomically and preserve immutable manual retries after ambiguous writes. Recheck destination access, group join floors, blocks, attachment limits and rate policy.
- Add web selection/recipient confirmation through existing CSRF/actor protection. The additive schema and message-tools setting require separate operator actions; legacy sites remain off and patch upgrades do not migrate data.

### [1.5.4] — development candidate, not installed

- Add authenticated native device/session listing and single/other-device revocation using the existing mobile grant tables. Keep the current grant server-derived, isolate users, omit token credentials and exclude expired connections from the active list.
- Preserve the browser device-management endpoint and its CSRF protection. No database migration, website installation or provider change is part of this source candidate.


### [1.5.3] — development candidate, not installed

- Add explicitly installed, opt-in group conversations, encrypted group names/photos, accepted invitations, owner/admin/member permissions and transfer, leave/removal and group deletion. New and returning members see only messages sent after joining; removed members lose API/file/push access.
- Keep direct-conversation identity and legacy inboxes intact; new clients explicitly request groups. Add group management to the web client through the existing framework/CSRF boundary.
- Preserve ordinary-message push independence and recheck sender eligibility before dispatch. Group calls remain unavailable; no video/group-call claim.
- Keep patch upgrades through153 non-migrating; groups and ordinary push have separate administrative schema gates. No website was updated by this source change.

### [1.5.2] — development candidate, not installed

- Add explicitly installed ordinary-message push registrations/outbox and a separate bounded worker. Use standard APNs alert/FCM, encrypted device tokens, authenticated grant ownership, dispatch-time read/mute/block/deletion checks and generic metadata-only alerts; experimental calls stay independent. Disabled and absent-schema sites retain their existing behavior.
- Carry the authenticated caller display name through the existing push worker/provider, so CallKit receives the actual name before the foreground chat fetch. Keep the call provider callback compatible with existing three-argument senders.
- Return explicit `peer_busy` or `caller_busy` (HTTP409) for native call start conflicts, after normal accepted-conversation and device/grant checks. Keep the ordinary web client and existing call retry IDs unchanged.

## 2026-10-22

### [1.5.1]

- Use a neutral graphite palette and inverse Narzan mark for browser authorization, completion and device pages.
- Keep the exact hashed CSS CSP, CSRF, PKCE, callback validation and cookie-free native token boundaries.
- Move current website and support references to narzan.com; retain GitHub sponsorship and author attribution.
- Group release notes by date while preserving all versions and Git history. No schema, site settings or existing web JS/CSS changes.

## 2026-10-21

### [1.5.0]

- Add native-only, explicitly consented call-quality feedback: integer stars and
  strictly enumerated issues, with no free text, audio or message content.
- Feedback is encrypted at rest, one immutable report per call/participant, exact
  original grant-bound, limited to ended calls with offer/answer negotiation and
  a 24-hour submission window. Media/push can remain disabled while reporting.
- Explicit `installExperimentalCalls` adds one feedback-only table; capability is
  advertised only once that table exists. Patch upgrades perform no schema work.
- Private bounded export requires both administrator and export authority (or
  superuser). Reports expire after 30 days via export/write/CLI maintenance.
- No existing web JS/CSS, base message schema, policy defaults or keys change.

### [1.4.9]

- Correct background call delivery under real ProcessWire current-user semantics.
  The CLI administrator gate remains; each caller is temporarily scoped only while
  rechecking conversation/block/restriction policy, and the original worker user
  is restored before provider delivery, including denial and exception paths.
- Add regression coverage for a guest CLI user and policy-denied callers. No web
  assets, schema, settings or encryption changes relative to1.4.8.

### [1.4.8]

- Add opt-in experimental one-to-one audio signaling for native revocable grants;
  accepted conversation, site policy, block/restriction and device ownership apply.
- Add explicit administrative creation of four additive calls-only tables,
  encrypted push registrations/SDP, idempotent starts/signals, one-device answer,
  ringing/heartbeat expiry and temporary authenticated TURN credentials.
- Add a bounded CLI push outbox sender using APNs VoIP and FCM HTTPv1. Provider
  credentials stay outside webroot/settings/Git; calls remain off until configured.
- Existing message tables, web JS/CSS, settings, encryption and login are preserved.
  Lifecycle upgrades143–147→148 remain no-ops; call schema is an explicit operation.
- Infrastructure templates and operator rollback/testing requirements documented.
  Production delivery and real audio are separate deployment gates.

## 2026-10-19

### [1.4.7]

- Add authenticated recipient-context resolution without creating a chat or sending a message; existing accepted/archived/pending pairs are resolved before first-contact policy is considered. Blocks, restrictions and trusted site hooks remain authoritative.
- Publish effective member-facing length, request, edit/delete and polling settings through an authenticated client-policy endpoint. Conversation payloads include server-derived send/attachment capabilities.
- Default the issuer to the first explicitly configured ProcessWire httpHosts domain when the advanced origin override is empty; never use runtime Host headers.
- Use the official exact Narzan callback when the advanced callback override is empty. Explicit custom lists still replace the built-in callback; no wildcard, dynamic registration or issuer/TLS relaxation.
- Correct web timestamps using additive offset-bearing ISO values, use configured text limits and pending-send capabilities, hide ineligible edit/delete actions on message reload, normalize timezone-aware mute times, and resume existing pairs from New message.
- Guard late web actions/search responses against navigation, preserve active searches/drafts during polling, and keep immutable IDs for manual send/start retries.
- Allow bounded1MiB JSON text requests so a configured50,000-code-point Unicode message fits without truncation.
- Additive, no-schema upgrade from143–146; browser CSS, encryption, permissions, sessions and existing mobile grant identities are preserved.

### [1.4.6]

#### Added

- Optional `message_info` discovery capability and read-only member `message-info` endpoint with stored sent time, authoritative read state, and the earliest retained reader/conversation audit timestamp. Legacy cursors without a matching audit report unknown read time honestly.
- Enforce active accepted participant, block/restriction, individual/conversation hiding, deletion, expiry and moderation boundaries without decrypting content or triggering expiry writes.
- Keep exact 143→146, 144→146 and 145→146 lifecycle upgrades free of schema, permission and encryption changes, preserving historical additive patch no-ops and all older/repeated/future upgrade guards. Preserve the optional, disabled-by-default WireWall integration introduced in 1.4.5. No web assets, roles, session behavior or site policy changes.

### [1.4.5]

#### Added

- Add an optional WireWall integration setting that registers only `/messenger-api/` and `/messenger-mobile/` through WireWall's module-owned API registry.
- Show whether WireWall is installed, supports the integration contract, and currently has the exact Messenger route registration.

#### Security

- Keep the integration disabled by default. Messenger authentication, authorization, rate limits, CSRF policy, PKCE and private response headers remain authoritative after a firewall exception is registered.
- Disabling or uninstalling the integration removes only the Messenger-owned registration and preserves administrator-managed and other module exceptions.
- Restore the previous Messenger checkbox value if WireWall synchronization fails.

#### Lifecycle

- Treat the exact 144→145 upgrade as a no-schema patch; no message, credential, encryption, permission or session data changes.

### [1.4.4]

#### Added

- Return the authenticated, eligible member's own login (`name`) and display label (`title`) as additive session fields for native account settings. Anonymous/restricted sessions omit these fields; no email, credentials, or other member's profile is exposed.
- Add optional `media_group_id` on live raster image messages (including genuine detected Apple HEIC), derived from an exact client retry-ID convention. Individual messages, private attachment checks, retries, storage schema and existing clients remain compatible. No grouping by timestamps or file names.
- Match the existing mobile consent/return documents to Narzan's muted green palette and compact corners while preserving their strict CSP and automatic callback.
- Skip schema/permission initialization for the exact 143→144 patch lifecycle; older and future upgrade paths keep their existing behavior.
- Preserve existing session IDs, browser CSRF, native member-only authority, error handling, and all web assets. No schema, encryption, route, retention, or policy change is required.

## 2026-10-18

### [1.4.3]

#### Fixed

- Accept the `audio/x-m4a` MIME signature reported by libmagic for genuine Apple AAC-in-M4A voice recordings, normalizing that detected alias to the existing `audio/mp4` voice validation contract. Filename/declared MIME alone never authorize content; encrypted storage, exact retry identity, membership and size/duration bounds remain unchanged.
- Add a genuine generated AAC/M4A silence fixture and regression checks for MIME sniffing, encrypted send/download, stable retry, invalid content and duration rejection. No client TLS/authentication or existing web behavior changes.

### [1.4.2]

#### Fixed

- Use the supplied Messenger reference style consistently on mobile consent, app-return fallback and device pages: graphite surfaces, lime actions, compact spacing and 12/16px corners, independent of the browser color preference.
- Preserve exact CSS-hash CSP, CSRF, PKCE and automatic HTTP 303 callback behavior. This patch changes mobile identity presentation only; the existing web messenger assets and API behavior are unchanged.

### [1.4.1]

#### Fixed

- Return to the registered native app with HTTP 303 immediately after valid browser approval; preserve a branded accessible fallback without presenting a raw technical success page.
- Render mobile identity consent/fallback with the owner-provided Narzan mark, responsive light/dark styles and a strict hash-based CSP. No external resources or JavaScript are introduced. Scope form-action to self plus the already validated callback scheme/origin for Chromium's post-form redirect checks.
- Keep browser SSO/2FA, CSRF, exact callback validation, PKCE and private token handling intact. Browser/OS chooser behavior still requires native runtime verification; production sites are not changed automatically.

### [1.4.0]

#### Added

- Explicit private contact books with stable `added_at`, independent of conversations; member discovery remains available separately.
- Durable per-participant chat mute and personal conversation deletion. Personal deletion hides existing messages and private attachments only for that participant; a later message restores the chat while older history stays hidden.
- Shared future-message auto-delete policies (one day, week, 30 days, or a custom UTC deadline) and author-only individual message expiry. Server reads enforce expiry immediately; bounded opportunistic/maintenance cleanup clears message ciphertext, encrypted attachment data and pending notification entries, retaining tombstones and audit IDs.
- Capability negotiation for contact books, chat mute/deletion and retention. Existing chats default to no expiry; no background worker is installed or enabled.

### [1.3.0]

#### Added

- Member presence with a 90-second heartbeat, encrypted bounded status text, invisible/offline privacy, and eligible member/contact directories.
- Authoritative peer read cursors, suppressed for blocked or restricted peers.
- Atomic, idempotent file and voice messages in accepted conversations. File metadata and bytes use authenticated encrypted database envelopes, with membership, hiding, deletion, block and restriction checks on every private download. No public file URLs or inline active-content rendering.
- Bounded JSON transfer: 8 MiB files, 4 MiB voice clips, optional 120-second recording duration. Existing text API and browser frontend contracts remain compatible.

### [1.2.1]

#### Fixed

- Require a trusted web-server HTTPS indicator for native identity and bearer requests. ProcessWire may infer its HTTPS flag from a client-supplied X-Forwarded-Proto header; that header alone no longer passes the mobile transport check. TLS proxies must configure the HTTPS server environment explicitly.
- Verified fresh installation and member/browser PKCE flows on disposable real ProcessWire/MySQL over locally verified TLS; see NARZAN.md for remaining deployment gates.

### [1.2.0]

#### Added

- Optional Narzan mobile authorization, disabled by default: exact HTTPS issuer and callback allow-list, browser session/CSRF consent, S256 PKCE, single-use two-minute codes, ten-minute access tokens, rotating thirty-day refresh tokens and connection revocation.
- Device credentials stored only as keyed hashes; refresh reuse revokes the connection. Native requests reject cookie/bearer mixing and never create a browser login session.
- Browser device-management page with CSRF-protected own-device revocation and bounded device metadata.
- Additive `GET sync` opaque revisions for conservative invalidate-and-refetch of cached messages, including edits and deletes older than the message-ID cursor. This is not an incremental event feed.
- Isolated domain, identity, transport and real HTTP fixture checks, without using a consuming site's database.

#### Fixed

- Retry recognition precedes new-message pending/rate limits, while actor, membership and recipient safety remain mandatory. Bind each client ID to the original conversation and immutable keyed body fingerprint; conflicting requests return 409.
- Keep hidden-for-self messages out of inbox previews and unread counts.
- Revalidate recipient eligibility outside policy hooks and when sending into existing conversations; fail closed when restriction lookup fails.
- Couple edits, deletes, hides and member state updates to their invalidation audit in transactions. Remove internal request/client fingerprints from edit responses.

#### Compatibility

- Browser routes, session authority, JSON envelopes, CSRF token format and existing member endpoints remain supported. A separate mobile endpoint family is opt-in; no native token permits administrative actions.
- Upgrade adds an immutable request fingerprint column and four retained mobile credential/rate tables. Existing ciphertext, associated data, encryption keys and browser sessions are not rewritten.
- Legacy edited/deleted messages without an original request fingerprint reject ambiguous retries rather than inventing a successful match.
- No site deployment, app registration, push delivery or production readiness is implied by this release candidate. See `NARZAN.md` for activation and disposable-site gates.

## 2026-10-01

### [1.1.0]

#### Added

- Added permission-gated administrative broadcasts for all eligible members or a selected ProcessWire role.
- Allowed `messenger-admin` users to enter the Process workspace without also granting moderation access; individual sections retain their own permission checks.
- Added audience preview, explicit confirmation, encrypted source storage, recipient snapshots, idempotent batch delivery, progress, cancellation, and lifecycle audit records.
- Added the responsive **Setup → Messenger → Broadcasts** workspace and broadcast detail view.
- Added a guided audience → message → review workflow, live role visibility and character count, stale-preview protection, and explicit zero-recipient handling.
- Added `broadcasts` dry-run and execution modes to the local maintenance CLI.
- Added public broadcast APIs and a real ProcessWire integration test covering encryption, delivery, replay safety, and authorization.

## 2026-09-30

### [1.0.2]

#### Fixed

- Render the conversation thread as a semantic section instead of a nested
  `main`, allowing the consuming page shell to remain the single main landmark
  across desktop, tablet, and mobile layouts.

## 2026-09-26

### [1.0.1]

#### Fixed

- Made the encryption-at-rest migration use an immediate write transaction on
  SQLite and omit its unsupported `FOR UPDATE` clauses there.
- Retained row-level migration locks on MySQL and PostgreSQL.

## 2026-09-19

### [1.0.0]

First public release.

#### Added

- Private one-to-one conversations between ProcessWire users, including Message Requests, accept/decline flows, unread counts, archive, mute, per-conversation email preferences, edit/delete windows, and hide-for-self controls.
- Idempotent sends, cursor pagination, recipient and bounded encrypted-message search, abuse limits, directional blocks, reports with preserved evidence, temporary restrictions, and audited moderation decisions.
- Same-origin, session-authenticated REST API with CSRF validation, private/no-store responses, explicit versioning, and rate limiting.
- Authenticated encryption at rest for message and report content using libsodium XChaCha20-Poly1305 with a dedicated configuration secret or ProcessWire `tableSalt` fallback.
- Responsive frontend application with LQRS colors and adapters for semantic styles, Designsystemet, UIkit, Bootstrap, Tailwind CSS, and custom mappings.
- ProcessWire administration dashboard, report queue, restriction management, permission-separated evidence access, and responsive configuration fieldsets.
- Optional notification outbox with selectable WireMail provider and a preview-first local CLI worker.
- Retain-on-uninstall data policy, public integration documentation, examples, tests, sponsorship metadata, and MIT license.
