# Messenger public API

This document describes Messenger 1.7.2 (ProcessWire module version `172`). Confirm the installed version and live site configuration before using it; documentation is not evidence that the module is installed or enabled.

## Loading the module

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Messenger')) {
    /** @var Messenger $messenger */
    $messenger = $modules->get('Messenger');
}
```

Inside a module, prefer `$this->wire()->modules->get('Messenger')`. Do not instantiate Messenger directly or query its tables from site templates.

## Permissions and capability checks

| Method | Meaning |
| --- | --- |
| `canUse(?User $user = null): bool` | Logged-in member with no active restriction |
| `canReceive(?User $user = null): bool` | Valid non-guest recipient with no active restriction |
| `canModerate(?User $user = null): bool` | Superuser or `messenger-moderate` |
| `canViewContent(?User $user = null): bool` | Superuser or `messenger-view-content` |
| `canAdmin(?User $user = null): bool` | Superuser or `messenger-admin` |

`messenger-export` and `messenger-delete` are reserved authorization boundaries for explicit site tooling. Moderation, evidence viewing, administration, export, and deletion are deliberately independent.

## First-contact policy

### `messagingDecision(User $actor, User $recipient, array $context = []): array`

Hookable as `Messenger::messagingDecision`. Returns `['decision' => 'allow|request|deny', 'reason' => '...']`. Core validation runs before configured first-contact behavior: login, restriction, recipient eligibility, self-contact, and either-direction block checks cannot be bypassed by browser input.

The site may hook this method to consult an authoritative match, connection, membership, or entitlement service. Never trust a posted relationship flag.

## Member operations

```php
startConversation(User $actor, User $recipient, string $body, string $clientId = '', array $context = []): array
sendMessage(int $conversationId, User $actor, string $body, string $clientId = ''): array
conversationsForUser(User $actor, string $scope = 'inbox', ?string $before = null, int $limit = 30): array
pendingRequestCount(User $actor): int
conversation(int|string $identifier, User $actor): array
conversationMessages(int $conversationId, User $actor, ?int $beforeId = null, ?int $afterId = null, int $limit = 50): array
editMessage(int $messageId, User $actor, string $body): array
deleteMessage(int $messageId, User $actor): void
hideMessage(int $messageId, User $actor): void
searchMessages(User $actor, string $query, int $limit = 30): array
searchRecipients(User $actor, string $query, int $limit = 10): array
acceptRequest(int $conversationId, User $actor): array
declineRequest(int $conversationId, User $actor): void
markRead(int $conversationId, User $actor, int $messageId): void
setArchived(int $conversationId, User $actor, bool $archived): void
setMuted(int $conversationId, User $actor, ?string $until): void
setEmailMode(int $conversationId, User $actor, string $mode): void
blockUser(User $actor, User $blocked, string $reason = ''): void
unblockUser(User $actor, User $blocked): void
reportMessage(User $actor, int $messageId, string $category, string $comment = ''): array
```

Every conversation/message method enforces actor membership. The caller never supplies sender authority or moderation state. Inbox scopes are `inbox`, `requests`, and `archived`; responses include `pending_request_count`. Pagination uses IDs/cursors rather than offsets. A supplied client ID must contain 16–64 URL-safe characters; replay returns the existing message.

Email modes are `instant`, `digest`, and `off`. Report categories are `spam`, `harassment`, `hate`, `sexual`, `fraud`, `privacy`, and `other`. Invalid input, access, state, or policy produces a ProcessWire exception; callers should show a generic user-safe error and must not reveal inaccessible-member existence.

## Moderation

```php
reports(User $actor, string $status = 'open', int $limit = 50): array
reportStatusCounts(User $actor): array
report(int $id, User $actor): array
updateReport(int $id, User $actor, string $status, string $resolution = ''): array
restrictUser(User $target, User $actor, ?string $until, string $reason): void
clearRestriction(User $target, User $actor): void
restrictionStatus(User $target, User $actor): array
activeRestrictions(User $actor, int $limit = 100): array
moderationStats(User $actor): array
```

Without `messenger-view-content`, report reads omit evidence body and reporter comment. Report decisions and restriction mutations are audited. Supported report statuses are `open`, `reviewing`, `resolved`, and `dismissed`; `reports()` also accepts `all`.

## Rendering and framework adapters

```php
renderApp(User $actor, array $options = []): string
frontendFrameworks(): array
frontendUi(): array
frontendAttributes(string $role, array $extra = []): string
```

`renderApp()` accepts `title` and `api` options, enqueues module assets, and renders the member application. Framework adapters retain Messenger behavioral classes while adding presentation attributes. `frontendAttributes()` serializes only escaped `class`, `id`, `role`, `title`, `aria-*`, and `data-*` scalar values.

Stable roles include `layout`, `tabs`, `tab`, `conversation`, `input`, `textarea`, `button_primary`, `button_secondary`, `button_tertiary`, `button_danger`, `avatar`, `badge`, `thread_header`, `request`, `bubble`, `composer`, and `dialog`. The site must load its framework CSS.

## Notifications

```php
mailProviderOptions(): array
mailProviderLabel(): string
runNotificationOutbox(int $limit = 50, bool $execute = false): array
```

The outbox method previews by default. Delivery requires `$execute = true`, enabled email/CLI settings, and a valid WireMail transport. Explicitly selecting a missing or unconfigured provider fails closed. Provider credentials remain owned by the WireMail module.

## Administrative broadcasts

```php
previewBroadcast(User $actor, string $audience = 'all', string $role = ''): array
createBroadcast(User $actor, string $body, string $audience = 'all', string $role = ''): array
broadcasts(User $actor, int $limit = 50): array
broadcast(int $id, User $actor): array
processBroadcast(int $id, User $actor, int $limit = 100, bool $execute = false): array
cancelBroadcast(int $id, User $actor): array
runBroadcastQueue(int $limit = 100, bool $execute = false): array
```

All member-facing broadcast methods require a superuser or `messenger-admin`. The Process module accepts either `messenger-admin` or `messenger-moderate` at its boundary, then limits each section to its specific permission; an administrator without moderation access is sent directly to Broadcasts. `createBroadcast()` snapshots eligible recipients and stores the source body under authenticated encryption. The audience is `all` or `role`; a role audience requires the ProcessWire role name.

`processBroadcast()` previews a batch unless `$execute` is true. Delivery creates or reuses one direct conversation between the administrator and each recipient, marks first contact accepted, and inserts one encrypted message with a deterministic client ID. This intentionally bypasses member first-contact, block, and sender-rate rules for the authorized administrative message. A recipient who becomes unavailable or restricted before delivery is skipped. Delivery rows, stable client IDs, transactions, and a three-attempt limit prevent duplicate messages during retries.

`runBroadcastQueue()` is intended for the local CLI worker. Execution fails closed unless the Messenger CLI setting is enabled. Creation, delivery, cancellation, conversation IDs, and message IDs are audited; message bodies are not written to audit metadata.

## Encryption at rest

```php
encryptionStatus(): array
migrateEncryptionAtRest(): array
```

Message bodies and report `comment`, `evidence_body`, and `resolution` fields use versioned libsodium XChaCha20-Poly1305 envelopes with random nonces and associated data bound to immutable context. Evidence fingerprints are keyed. Authentication failure is fatal and never falls back to plaintext.

The key is domain-separated from `$config->messengerEncryptionKey`, or from `$config->tableSalt` when the dedicated value is absent. Neither is stored in Messenger tables. `encryptionStatus()` exposes readiness, key-source name, and plaintext counts without content or key material. `migrateEncryptionAtRest()` is a transactional legacy migration and requires a verified backup. It is not a key-rotation API.

Because randomized ciphertext cannot support SQL substring matching, `searchMessages()` decrypts a bounded window of the newest 1,000 accessible, visible messages.

## REST API

The private, same-origin route is `/messenger-api/v1/{resource}/` and uses the current ProcessWire session. First call `GET session`; every POST must supply its CSRF token in JSON or the declared request header.

`GET session` retains `is_login`, `user_id`, `can_use`, and `can_moderate`.
From 1.4.4, logged-in members with `can_use=true` also receive their own `name`
(site login) and `title` (display name, falling back to title/login). These fields
are omitted for guests and restricted users. They never accept a target user ID
and expose no email or credentials. Browser CSRF and native member-only semantics
are unchanged; older clients may ignore these additive fields.

GET resources:

- `session`
- `inbox?scope=inbox|requests|archived`
- `conversation?id=...`
- `messages?id=...&before=...` or `messages?id=...&after=...`
- `search?q=...`
- `recipients?q=...`

POST resources:

- `start`, `send`, `accept`, `decline`, `read`
- `edit`, `delete`, `hide`
- `archive`, `mute`, `notification-preference`
- `block`, `unblock`, `report`

Responses opt into no CORS access and emit private/no-store, noindex, nosniff, and frame-denial headers. REST errors are normalized; do not use them as an existence oracle.

## Narzan mobile access and cache synchronization

The browser v1 contract above remains supported. Mobile identity is a separate opt-in
endpoint family. See [NARZAN.md](NARZAN.md) for discovery, consent, tokens, transport,
revocation and deployment prerequisites. A valid bearer may call the member v1 API;
cookie/bearer mixing is rejected and a failed bearer never falls back to a cookie.
Native `session` responses omit browser CSRF and do not advertise moderation.

```php
syncState(User $actor, string $revision = ''): array
```

`GET /messenger-api/v1/sync/?revision=<previous opaque revision>` returns
`revision`, `changed`, `reset_required` and `strategy=invalidate_and_refetch`.
A first request requires an initial snapshot. When reset is required, invalidate
cached member content and refetch through participant-checked pagination; do not
use `messages?after=` to reconcile old edits/deletes. Check the revision again
before publishing a rebuilt snapshot. No audit identifiers/content are returned.
This conservative strategy does not claim an incremental ordered event feed.
Existing web polling does not automatically consume this new endpoint.

Replays must match the original conversation and keyed body fingerprint. A replay
returns the current authorized message representation, including its edit/delete
state. Reusing a key with another operation returns HTTP409 and
`code=idempotency_conflict`. Existing edited/deleted legacy rows without an immutable
fingerprint cannot safely be replayed. Internal client/fingerprint fields are not
public response data.

## Configuration

Defaults are returned by `Messenger::getDefaultConfig()`: public path `/messages/`, Designsystemet adapter, Message Requests first-contact mode, 5,000-character messages, rate limits, 15-minute edit/delete windows, 4/20-second polling, email and CLI disabled, and retention disabled. Treat live ProcessWire module settings as authoritative.

## Internal and lifecycle boundaries

`handleRestRequest()`, `getModuleConfigInputfields()`, `install()`, `upgrade()`, and `uninstall()` are framework lifecycle/transport entry points, not site-domain APIs. Database helpers, encryption helpers, audit helpers, `MessengerRestApi`, and `ProcessMessenger` internals are unsupported.

Do not query or write `messenger_*` tables, expose `direct_key`, client IDs, audit metadata, or evidence snapshots, or call private/protected methods. Uninstall retains tables and permissions. There are no deprecated public APIs in 1.1.0.


## Narzan member features (1.4.0)

Discovery advertises `features:["presence","directory","attachments","read_receipts"]`. Clients must treat missing features as unsupported and preserve compatibility with older servers.

All routes below use the existing `/messenger-api/v1/{resource}/` transport and its cookie/CSRF or native bearer authentication. They do not enable mobile identity automatically.

- `GET presence`: own `{status,text,expires_at}`; `expires_at` is Unix seconds. `POST presence {status,text}` selects `online`, `away`, `busy`, `invisible` or `offline` and refreshes a 90-second heartbeat. Status text is at most 120 characters, encrypted at rest. `POST presence {heartbeat:true}` renews only activity expiry, retaining the latest server status/text across devices; an absent row initializes online with empty encrypted text. Heartbeats cannot include status or text. Clients must use heartbeat mode for periodic renewal rather than replaying cached preferences. Owner preference survives expiry; peers see expired/invisible/offline as exactly `{status:"offline",text:"",expires_at:0}`.
- `GET directory?q=&limit=30`: eligible members, bounded to 30; empty query provides the default directory. `GET contacts` retains the 1.3.0 list of existing conversation peers. The separate `GET contact-book` surface in 1.4.0 lists explicitly added private contacts; conversations do not create saved contacts automatically. Both exclude self, either-direction blocks and restricted recipients. Directory is a member discovery surface, not an external directory or address-book upload. Responses are member objects with `presence`.
- Conversation objects add integer `other_last_read_message_id`; blocked/restricted peers expose zero. This is a read watermark, not a device-delivery receipt. A successful send is server acknowledgement; a peer read cursor at or above the message ID is read.
- `POST send-attachment {id,client_id,body?,filename,mime_type,kind,data_base64,duration_ms?}` atomically creates a message plus encrypted attachment. Existing accepted conversations only; no files in message requests. `kind` is `file` or `voice`; maximum decoded sizes are 8 MiB and 4 MiB respectively. Empty files are rejected, filenames are bounded to 180 characters and cannot contain paths/control characters. Voice requires a declared and detected supported audio/container MIME; duration is optional 1–120000 milliseconds (client supplied, not independently measured). Generic files use the detected MIME and are treated as opaque bytes. The same client ID binds the entire canonical payload, including bytes, name, kind and duration; conflicts return 409. Replays produce one message and one attachment.
- Message objects optionally add `attachment:{id,filename,mime_type,size,kind,duration_ms}`. `GET attachment?id=123` returns this object plus `data_base64`, only to an eligible unblocked participant in the accepted active conversation while the message remains visible and undeleted. No permanent/public download URL exists. Clients must write files to private storage and share through explicit platform mechanisms; do not render HTML/SVG as application content or attach bearer tokens to external URLs.

From 1.4.4, live file attachments with a detected JPEG/PNG/WebP/GIF/HEIC MIME may expose
optional `media_group_id`. It is the 43-character base64url token from the exact
client ID convention `album_<43 base64url characters>_<single index 0–9>`.
It is presentation metadata only; the complete client ID stays private. Partition
groups by conversation, sender and this token, retain message order and each
message's individual read/expiry/download checks. Ordinary files, voices, deleted
messages and nonconforming IDs omit the field. Polling and idempotent retries
return the same group token. Unrelated images must never be grouped by time or
name; the protocol does not make a batch atomic or change per-file size limits.

The JSON request body limit is 12 MiB only for `send-attachment` (1 MiB elsewhere, including configured50,000-code-point text with JSON Unicode escaping). Configure the consuming HTTP server/PHP body limits to allow that request size. Encrypted metadata is stored separately, so ordinary thread polling never selects or decrypts attachment bytes. Downloads are JSON base64; clients should bound responses to 12 MiB. Transfer is memory bounded by the stated limit, not streaming/resumable. These limits are per attachment, not a total user or installation storage quota. Before activation, the site owner must provision storage capacity and choose retention/deletion policy. The 120-second duration field validates client metadata; server-side codec duration parsing is not implemented, so this is not a server-enforced media duration guarantee. Audio transcription, virus scanning, push, delivery-to-device receipts and media retention/purge jobs are not implemented. Deleted/hidden media is denied at download, and encrypted storage is retained consistently with existing module uninstall behavior. Existing web clients display the caption/filename fallback; their attachment controls are unchanged.

Public PHP methods: `presence(User)`, `heartbeatPresence(User)`, `setPresence(User,string,string='')`, `memberDirectory(User,string='',int=30,bool=false)`, `sendAttachment(int,User,array,string)`, and `attachment(User,int)`. They enforce the same domain authorization as REST. Existing `sendMessage` signature is unchanged.


## Contact books and conversation settings (1.4.0)

Discovery adds `contact_book`, `chat_mute`, `chat_delete`, and `retention`. New fields use Unix UTC seconds, without inferring an offset from legacy date strings.

- `POST contact {user_id:123,added:true|false}` adds/removes only the authenticated user's contact. Returns `{user_id,added,added_at}`; `added_at` is an integer timestamp or null. Repeated adds preserve the original timestamp. Contacts never create a conversation or send a message. `GET contact-book?q=&limit=30` returns eligible saved members with `added_at`; the legacy `GET contacts` route retains conversation-peer semantics for older clients; `GET directory` includes the authenticated user's saved timestamp or null. Blocked/restricted members are excluded, while removing a previously blocked contact remains possible. Removal accepts a positive integer target ID and deletes only the actor’s matching saved row without resolving that target: nonexistent, blocked, restricted and unsaved IDs all return the same successful removal shape. Invalid/nonpositive IDs are rejected uniformly. No existing chat is silently imported into the new contact book.
- `POST mute {id:123,muted:true|false}` persists mute for the current participant only and returns the conversation. Conversations add `muted` and nullable `muted_until_epoch`; existing `muted_until` remains compatible. The existing legacy `POST mute {id,until}` form remains valid. Indefinite mute uses a year-2100 deadline. Email enqueue and actual delivery both enforce current mute; no push service is implied.
- `POST retention {id:123,seconds:86400,at:null}` sets a shared future-message policy. `seconds` supports `0` (off), `86400` (day), `604800` (week), or `2592000` (30 days). Alternatively `{id,seconds:0,at:1790000000}` sets an absolute UTC deadline more than 60 seconds and at most ten years ahead. The accepted, unblocked conversation's eligible participants may change this policy; clients must disclose that it affects both participants' **future** messages. It does not rewrite existing deadlines. Conversation fields are `auto_delete_seconds` and nullable `auto_delete_at`. Conversations also expose integer `message_revision` (count of append-only audit events for shared existing-message expiry/edit/delete plus the current actor’s own hides/personal-history deletions; counting events detects late commits even when audit IDs commit out of order). A changed revision requires clients to discard loaded older message pages and refetch, even when no new message ID exists. Ordinary send/read/presence events do not change this revision, preserving normal pagination. A passed absolute deadline prevents new messages until the policy is changed.
- All newly created text/file/voice messages expose nullable `expires_at`. Once assigned, a retry preserves the original message/deadline even if the shared policy changed. `POST message-expiry {message_id:123,expires_at:1790000000|null}` lets only the original author set/change/clear an individual future deadline while the message remains visible and undeleted. The same bounds apply. Changing expiry cannot restore already expired content.
- `POST delete-conversation {id:123}` returns `{deleted:true}`. It clears the actor's chat row and existing history using an immutable message-ID cutoff; it neither deletes the peer's history nor removes a saved contact. Direct reads, search, previews and private downloads enforce that cutoff. A subsequent message restores the chat with old messages still hidden; messages arriving in the same second are handled correctly by IDs. This is personal hiding, not physical destruction of the peer's messages.
- `POST expire {}` performs an authenticated, bounded sweep of up to 100 expired messages from the actor's conversations. Routine authorized member operations opportunistically run the same bounded sweep. All reads independently enforce expiry even when cleanup is deferred, so larger queues do not expose expired body/media bytes. Expired rows become tombstones with empty body, a non-null `deleted_at`, original IDs and `expires_at`; they are excluded from unread counts/search. The server physically removes encrypted attachment payload and metadata and clears stored message ciphertext on cleanup. Pending notifications are cancelled. Existing report evidence remains retained under its separate moderation policy; recipients may already have copied/downloaded content and expiry cannot revoke external copies. The unchanged legacy web frontend polls only newer message IDs and does not replace already rendered rows: a loaded web tab can continue displaying cached text until the conversation is reopened. Clients implementing retention must evict expired cached content and refetch on relevant state changes. No production web deployment is performed by this release.

For installations needing timely physical cleanup during inactivity, the owning operator may explicitly configure its existing maintenance runner to call `sweepExpiredMessages(100,true)` in bounded batches, or use `bin/messenger expiry --root=/path/to/site --dry-run|--execute --limit=100` after enabling CLI. No local daemon, cron entry, server scheduler, email delivery, or retention default is created/enabled by this release. Without a runner, read access expires at the deadline and physical deletion happens at the next relevant authorized request. Tombstone/audit IDs, contact timestamps and previously filed report evidence are retained; this is not an account-erasure system. Back up and review rollback before applying schema changes to a consuming site.

Public PHP additions: `contactBook(User,string='',int=30)`, `setContact(User,User,bool)`, `removeContact(User,int)`, `setConversationMuted(int,User,bool)`, `setConversationRetention(int,User,int=0,?int=null)`, `setMessageExpiry(int,User,?int)`, `deleteConversationForUser(int,User)`, and `sweepExpiredMessages(int=100,bool=false,?User=null)`. Maintenance calls without an actor are trusted server-side operations, not a remotely available unscoped export/delete API.


## Native browser return (1.4.1)

After browser sign-in and a valid explicit CSRF-protected approval POST, the mobile authorization endpoint returns HTTP `303` with `Location` set to the exact validated, allow-listed app callback carrying the short-lived code, original state and issuer. Native clients still verify callback/state/issuer and exchange once using PKCE before showing connection success. There is no password endpoint or embedded webview.

The consent and fallback HTML are self-contained Narzan-branded documents with light/dark styles and no external fonts, images, scripts or network requests. CSP allows only the exact embedded CSS hash. The originating consent document keeps `form-action 'self'` plus the already validated callback's private scheme (or exact HTTPS origin), because Chromium also checks redirect targets after form submission. The server's exact callback allow-list remains authoritative. Other mobile identity documents keep self-only form submission.

A styled accessible Return to Narzan link remains in the 303 response body for agents that render it; some browsers intercept/follow the Location without displaying that body. App chooser/permission prompts and fallback rendering depend on the browser/OS. Native cancellation and retry must remain available; this does not promise a chooser-free return on every browser. It does not replace arbitrary site SSO, passkeys or two-factor authentication with native password entry.

## Optional message information (1.4.6)

Feature-detect `message_info` in native discovery before calling
`GET /messenger-api/v1/message-info/?message_id=123`. Browser/session callers use
the same existing same-origin member transport. The public domain equivalent is
`$messenger->messageInfo($messageId, $actor)`. The response is:

```json
{"message_id":123,"sent_at":"2026-10-19 03:00:00","is_own":true,"is_read":true,"read_at":"2026-10-19 03:04:05"}
```

`sent_at` is the stored message creation timestamp. `is_read` uses the current
authoritative read cursor: the other participant for an outgoing message, the
current member for an incoming message. If read, `read_at` is the earliest existing
`conversation_read` audit whose cursor covers this message, scoped to that reader
and conversation. A retained cursor with no surviving matching audit returns
`is_read=true, read_at=null`; clients must display an unavailable time rather than
invent one. Times use the existing server `created_at` datetime format and server
timezone, with one-second precision; they are server observations, not proof that a
human viewed pixels. No client clock, delivery time or presence timestamp is used.

Only current, eligible members of an active accepted unblocked conversation can
query their visible messages, including messages older than the latest page.
Deleted, expired, moderated, individually hidden and conversation-hidden messages
receive the same unavailable denial as unrelated or absent messages. Restricted
peers and actors are denied. This metadata read does not decrypt content, return
profiles, audit bodies or session data, mark a message read, sweep expiry, or write
audit records. It uses one message lookup and at most one conversation/reader-scoped
audit aggregate. Existing web assets, schema, roles, keys and policies are unchanged.

Exact 143→146, 144→146 and 145→146 patch upgrades skip schema/permission work;
historical exact 143→144, 143→145 and 144→145 no-ops remain. Older, repeated and future lifecycle paths
retain their existing behavior. Version 1.4.3/1.4.4 clients can keep their existing
sent/read cursor status and show that exact read time is unavailable.

## Native/site policy parity — 1.4.7

`clientPolicy(User $actor): array` / authenticated GET `client-policy` exposes only effective member settings: first-contact mode, maximum message length, pending-request allowance, edit/delete windows, and bounded conversation/inbox polling intervals. Infrastructure, email credentials, encryption material and firewall configuration are excluded. `recipientContext(User $actor, User $recipient): array` / GET `recipient-context?user_id=ID` returns an existing participant-authorized conversation or a server-derived allow/request/deny decision, without sending a message or creating a conversation. A configured first-contact mode is only a default: trusted messagingDecision hooks remain authoritative for each new pair. Existing chats are resolved before a new-pair decision. Blocks/restrictions/recipient eligibility still apply. Discovery advertises `client_policy` and `recipient_context`.

Conversation payloads add `can_send` and `can_attach`; pending requesters can send only their remaining configured allowance, recipients must accept, and files require an accepted chat. Message-page payloads add `can_edit`, `can_delete_for_everyone` and `created_at_iso`; conversations add `sort_at_iso`. ISO fields include the server timezone offset, while legacy timestamp fields remain unchanged. Eligibility is advisory presentation data; mutation endpoints recheck ownership, time windows and policy at execution.

An empty advanced `mobile_redirect_uris` override now selects exactly `org.smnv.narzan.dev://auth/callback`, the registered official Narzan identity. An explicit list replaces that default. Mobile access remains off by default, with an explicit canonical HTTPS origin (or the first trusted ProcessWire httpHosts entry), S256 PKCE, state/issuer checks, consent, CSRF and one-time code exchange. Never infer the issuer or callbacks from request headers; never allow wildcard or dynamically supplied callbacks. Site-specific sign-in path/return-parameter configuration remains necessary when the website provides that integration.


## Experimental native audio calls (1.5.0)

Calls are disabled by default and require explicit private operator configuration,
authenticated TURN, APNs/FCM and `installExperimentalCalls($administrator)` for the
five additive calls tables. No base messaging migration runs during patch upgrade.
See [operator deployment and rollback](deploy/calls/README.md).

Native bearer-grant endpoints: GET `call-config`, `call-inbox`, `call-state?id=...&after=...`,
`call-ice?id=...`; POST `call-device`, `call-start`, `call-signal`. Browser sessions
cannot invoke these endpoints. Grant revocation, accepted active conversation,
site-send capabilities, blocking/restrictions and selected-device ownership apply.
SDP and push tokens are encrypted at rest; media uses WebRTC outside PHP. CLI-only
`processExperimentalCallPushes` dispatches at most20invitations per invocation.
This does not add video/group calls, message E2EE or background message delivery.

### Consented call quality feedback (1.5.0)

The operator must explicitly re-run `installExperimentalCalls($administrator)`
after backing up, to add `messenger_call_feedback`. The patch lifecycle creates no
schema. `GET call-config` advertises `feedback_enabled` only when that table is
available; this capability is independent of `enabled` (media/TURN/push).

A native bearer can submit `POST call-feedback` with exactly:

```json
{"id":"call-uuid","stars":4,"issues":["echo","delay"],"consent":true}
```

`stars` is an integer 1–5. `issues` is a distinct list with at most nine values from
`no_audio`, `quiet`, `distortion`, `echo`, `delay`, `dropouts`, `connection`,
`speaker`, `microphone`; an empty list is valid. Free text, recordings, conversation
content, addresses, device identifiers and extra fields are rejected. Explicit
consent is mandatory; clients must offer Skip and never silently send a local
rating. Success is `{"saved":true}` inside the normal REST response envelope.

The current non-revoked native grant must be the original caller or answering
device, with the matching participant identity. Calls must be ended, accepted,
with both offer and answer signaling and created within the last 24 hours. This
proves negotiation, not that audio was audible. Calls still ringing, merely missed,
rejected, unnegotiated, another device, outsiders and revoked grants are denied.
Feedback remains possible after calls are disabled or the peer is blocked: this
metadata-only operation cannot resume media, send messages or access peer content.
One report per call and participant is immutable; identical canonical retries
return success, changed retries return `idempotency_conflict` (409).

Feedback content is encrypted with the existing site key. There is no public
report-reading REST endpoint or automatically created dashboard. Server-side
`experimentalCallFeedback(User $actor,string $after='',int $limit=100)` is the
supported bounded export method; it requires superuser or **both**
`messenger-admin` and `messenger-export`. It returns `items` (call ID, participant
user ID, submission epoch seconds, stars, issues) and a `next` composite cursor.
Pass `next` unchanged to continue, until null. Limits are 1–200. Export includes
participant identifiers only for the site's authorized operator; it is not
anonymous analytics. Do not publish these records or query the table from templates.

Reports older than 30 days are excluded and physically purged on submission,
private export or the existing administrator CLI push-maintenance invocation,
including when calls are off. An idle website with no such execution requires
operator-scheduled maintenance for timely physical removal; no timer is installed
or enabled automatically. Site backups have the operator's separate retention.
Call/signaling records keep their existing one-day cleanup; the feedback is
independent and cannot restore a call. Uninstall continues retaining tables.


## Ordinary native message notifications — 1.5.3 development candidate

`installMessagePush(User $administrator)` is an **explicit** operator migration.
`messagePushReady(): bool` checks configuration and schema without creating them.
`POST /messenger-api/v1/message-push-device/` requires a cookie-free native bearer
grant and `enabled`/`sound` booleans, `platform` (`ios`/`android`) and `token`.
Tokens are encrypted at rest, never returned or logged. Legacy registration binds
a token to one current grant; user/device revocation, mute, read state, hidden/deleted/expired
messages and blocks are rechecked before dispatch. Registration cannot select a
user or conversation. Discovery advertises `message_push` only when ready.

Messenger1.7.0 also advertises `account_push` when message push is ready. A client
with a validated local account registry may explicitly add `account_binding:true`
to registration. The response acknowledges `account_binding` and its authenticated
`connection_id`. Never choose the user/grant in the request. Legacy bodies and
responses are unchanged. Opted-in accounts may share one provider token without
evicting each other; disabling/revoking one grant affects only its binding.

Bound message and event-reminder `accountKey` values append a fourth `#connectionId`
segment to `issuer#siteId#memberId`. Provider callback signatures remain unchanged.
The client must resolve that exact existing stored connection and validate member
identity before fetching/opening a chat; never fall back to the currently selected
account or start authentication from a push URL. Legacy three-segment keys require
an unambiguous stored identity. The scope is routing metadata, never a credential.

The existing encrypted device row/unique hash column is reused with a domain-
separated token/platform/grant hash; no implicit DDL, new schema or provider change.
Registration mode is verified against decrypted token/hash at dispatch. Operator
configuration, mute/silent/visibility/revocation checks and worker bounds remain
controlling. Call/VoIP account routing is a separate capability and is not supplied
by `account_push`. Native multi-account activation still requires client routing.
See [candidate verification and boundaries](docs/ACCOUNT_PUSH.md).

`processMessagePushes(User $administrator, callable $send, int $limit=20)` drains
at most20eligible entries, with five bounded attempts and seven-day outbox
retention. A provider callback receives `(platform, token, messageId, chatId,
accountKey, sound)`; only identifiers and a generic alert reach APNs/FCM, never
message bodies/sender names. Ordinary iOS tokens are distinct from PushKit tokens;
the topic is the app bundle, push type `alert`, never `.voip`.

Run `bin/message-push-worker.php /absolute/site/root` only after verified backup,
operator approval and private `$config->messengerPush` setup (`enabled`,
`push_ready`, `worker_user_id`, and the same provider credential-path fields
used by the existing call sender). No worker or site upgrade is installed by
this source change. Android OS force-stop can prevent FCM delivery; do not
promise delivery in that state. OS notification/Focus settings remain controlling.

## Opt-in group conversations

Messenger1.5.3 adds the explicitly installed group core. See [group API, permissions and verification boundaries](docs/GROUPS.md). Existing inbox/search callers stay direct-only unless they request `include_groups=1`; discovery only advertises `groups` after operator activation and schema readiness. New group messages use the existing authorization/encryption/media transport; group calls and end-to-end encryption are not implied.

## Account-bound call registration — 1.7.0 candidate

When `accountCallsReady()` confirms existing operator configuration/schema,
discovery advertises `account_calls` and call-config includes
`account_binding_supported:true`. `POST call-device` may explicitly add boolean
`account_binding:true`; the reply acknowledges that mode and the authenticated
`connection_id`. No request field chooses a user/grant. Existing registration
bodies/replies remain unchanged; this does not install or enable calling.

Bound invitation callbacks receive a fifth `accountKey` argument containing exact
`issuer#siteId#memberId#connectionId`; legacy callbacks remain four arguments. The
built-in APNs/FCM sender includes it as `narzan_account`. Custom providers must
preserve that argument for new clients. The token's authenticated-encryption
context distinguishes binding mode without schema changes or ambiguous token
prefixes. Fresh device/recipient/caller-grant checks precede dispatch.

This scope is only a routing hint into an already stored, server-confirmed native
account. Native call state/media acceptance still requires authorization; never
open a URL or use current-account fallback from push data. VoIP native lifecycle
and multi-account activation are separate acceptance gates. See
[verification, compatibility and collation fix](docs/ACCOUNT_CALLS.md).

## Native device sessions — 1.5.4

Discovery feature `device_sessions` exposes `devices`, `revoke-device` and
`revoke-other-devices` through the authenticated member API. See the
[ownership, credential, expiry and delivery contract](docs/DEVICE_SESSIONS.md).
No new schema is introduced; existing browser device management remains CSRF
protected. A revoked native session must stop local audio and clear private
session state. Already delivered plaintext/provider alerts cannot be recalled.

## Explicit forwarding — 1.5.5 candidate

Disabled by default. An administrator must call `installMessageTools($operator)` and separately enable `message_tools_enabled`; discovery then includes `forwarding`, and browser session includes `forwarding_supported`. No member request or patch upgrade creates these tables.

`POST /messenger-api/v1/forward/` accepts `{from_id, to_id, message_ids, source_versions, client_id}`. IDs are positive integers; select1–10 distinct messages and freeze each displayed `content_revision` string in the `source_versions` object keyed by message ID. Reusing a client ID with another selection or destination returns409 `idempotency_conflict`; changing a selected message returns409 `message_changed`. Refresh and explicitly select again after a definitive stale-selection failure.

Returns `{conversation, messages}`. Text, captions and independently encrypted file/voice copies preserve the original content, marked `forwarded:true`; the authenticated actor is the new sender. Source user/chat/message identities are not disclosed. A photo batch has a fresh explicit album ID. The source actor must currently see every selected message; target must be accepted, sendable, unblocked and within site limits. Group membership/history floors apply. Total file bytes are capped at32MiB per atomic operation. Existing per-file and per-user restrictions still apply. All copies commit or roll back together.

A matching retry returns the existing destination copies, even after the original was deleted or source access ended, but still requires current destination access. It cannot restore a hidden/deleted copy. Store a manual retry ID and its entire immutable payload until acknowledgement; do not automatically retry or replace the payload after a network timeout. Native Bearer requests retain credential separation; browser writes require the existing CSRF token and actor guard.

`messenger_forward_ops` stores the actor, client ID, keyed request fingerprint, destination, encrypted result IDs and creation epoch. `messenger_message_markers` stores only a trusted message-kind marker. As with existing Messenger data, uninstall retains these tables. This is encryption at rest, not end-to-end encryption. The new client/web controls do not activate the feature on an existing site.

## Reactions — 1.5.6 candidate

The explicit `installMessageTools($operator)` migration now also creates `messenger_reactions` and its per-member serialization table `messenger_reaction_locks`. Upgrade alone does not. Sites with the1.5.5 forwarding tables can continue forwarding without it; repeat the operator migration to add reactions. The existing `message_tools_enabled` setting and the table must both be present. Discovery advertises `reactions`, browser session `reactions_supported`.

`POST /messenger-api/v1/reaction/` accepts `{message_id:integer, emoji:string|null}`. Six supported values: 👍 ❤️ 😂 😮 😢 🙏. Each member has one current reaction per message. Sending the same desired value again is idempotent; null explicitly clears only the actor's state. No actor identity is accepted from the payload. Returns the current decorated `Message`, including `reactions:[{emoji,count,mine,users:[{id,name,title}]}]`. Counts/identities come from eligible actual participants; group join/rejoin history floors and removal apply. Deleted/expired/hidden/unavailable messages cannot be reacted to; unavailable requests and blocked/unsendable conversations are rejected. A per-member lock serializes writes across conversations. Sixty state changes per member in a fixed60-second epoch window; an identical retry adds no audit event and is not charged again.

Emoji values are individually encrypted with message/user-bound authenticated envelopes. No notification or external provider request is generated for a reaction. A message's forwarding `content_revision` deliberately excludes reactions. Conversation/sync revisions change, so other clients refresh the visible reactions. Browser polling preserves the composer, draft, focus and non-bottom scroll when only message content revision changes. Native reaction requests do not hold the navigation mutex; late acknowledgements never reopen an abandoned conversation.

## Personal chat pins and scoped message pins — 1.5.7 candidate

Run the explicit administrator `installMessageTools($operator)` migration to add `messenger_chat_pins`, `messenger_message_pins` and `messenger_pin_locks`, then separately enable message tools. Patch upgrades create no table. Existing forwarding/reactions remain independently available on their previous schemas. Discovery advertises `pins`, browser session `pins_supported`, only when all pin tables are present.

- `POST pin-chat {id:integer,pinned:boolean}` sets the authenticated actor's desired state. Five personal chat pins per member across Inbox/Archive. Unpinning clears only that actor's stored pointer and is idempotent, even after access to the original conversation ended. Pinning requires an accepted active conversation. Blocks do not prevent personal organisation of accessible history.
- `GET inbox?include_pins=1` includes up to five authorized `pinned_items` on the first page of Inbox/Archive. The ordinary `items` and date/ID cursor remain unchanged, and old callers do not opt in. Clients prepend/deduplicate this separate prefill and retain the original page size for Load more. A pin archived by its actor stays in Archive; personal chat deletion clears its pin. Conversation metadata adds actor-scoped `pinned_at`.
- `GET pins?id=conversation` returns `{items:[{scope,created_at,message}],can_pin_shared}` with up to five personal and five shared visible original messages. It never returns another member's personal pins. Hidden/deleted/expired/pre-join messages and inaccessible groups are omitted or denied; pinning never duplicates or bypasses original attachment authorization.
- `POST pin-message {id,message_id,pinned,scope}` accepts `scope:"personal"` or `"everyone"`; no user/scope-owner ID comes from the payload. Personal is the client default. Sharing requires accepted, unblocked send rights; group sharing/removal additionally requires current owner/admin role. Regular group members retain personal pins only. Five personal messages per member/conversation, with a limit error when full. Up to five shared messages: an explicitly chosen new shared pin replaces the oldest shared pointer when full; clients explain this before confirmation. Exact desired-state retry never replaces another pointer. A shared pin may be removed by another eligible direct participant/group administrator. Removing a member does not remove an otherwise valid shared pin, but the former member cannot view or change it.

Pin changes are serialized per actor across conversations and under the owning conversation lock, capped at60actual changes per fixed60-second epoch window. Repeating the existing state is not charged. Tables contain pointers/epochs/creator IDs, never duplicate message bodies/files. They are retained on module uninstall along with existing Messenger data. New personal pins may clean only the actor's own inaccessible stale pointers; globally deleted/expired shared pointers are removed before capacity checks. An administrator can explicitly add a new shared pin even when the oldest pointer is inaccessible to them; only the pointer is replaced, never disclosed.

Personal/shared message revisions are scoped correctly. Native and web clients can load a bounded authorized older-message window and return explicitly to latest; polling does not overwrite that selected window. The web composer/draft survives the jump. A delayed response cannot replace a different conversation. No pin push or production installation is implied.

### 1.5.7 member-window correction

Reaction/pin counters use persisted epoch windows under the existing member locks,
independent of the site's PHP timezone, DST and audit retention. The explicit
operator migration adds `window_started_at` / `event_count` to existing1.5.6
reaction-lock rows without dropping their user IDs. Readiness stays false until
both columns exist; no member-side DDL is attempted. Exact desired-state repeats
are still free and a failed transaction does not consume a change.


## Paginated conversation history search

`searchConversationHistory(User $actor, int $conversationId, string $query,
?int $beforeId=null, int $limit=30): array` and authenticated
`GET /messenger-api/v1/search-history/?id=123&q=words&before=456&limit=30` scan
up to500authorized historical rows per request. Query length is2–128characters.
Result: `items` (normal message DTOs, newest first), `next_before` (exclusive
integer cursor or null), `scanned`, `complete`. Follow the cursor even when no
match was found in that scan; only `complete=true` establishes exhaustion.
The scan has no1,000-message history cutoff and creates no plaintext index.
Conversation/member visibility, group join floors, hides, expiry and moderation
remain authoritative on every batch and later original-message/file access.
The discovery feature is `history_search`; authenticated session metadata adds
`history_search_supported=true`. The bundled web client exposes Search chat history
in thread actions, with cancellation and bounded result pages. Existing global `search` remains
unchanged for legacy clients. No schema, worker or production migration is added.


## Private Saved messages

The operator setting `saved_messages_enabled` is off by default. With it enabled,
`openSavedConversation(User $actor): array` or authenticated
`POST /messenger-api/v1/saved-messages/` explicitly creates/opens the actor's one
private conversation (`type=saved`, one participant, owner as requester/recipient).
Repeated opens are idempotent and never reset hidden-history cutoffs. This uses
existing encrypted messages/attachments and requires no new schema. Browser
writes retain CSRF; posted user IDs cannot choose another owner.

Discovery advertises `saved_messages` only when enabled; session metadata adds
`saved_messages_supported`. Inbox adds optional `include_saved=1`, and the public
`conversationsForUser`/`pinnedChats` methods append `includeSaved=false`. Legacy
callers retain direct/group lists. Saved content is accessible only to its owner
under normal site authentication and policy, synchronized through that site's
account. Existing site storage/moderation/retention rules remain applicable;
this is not E2EE or an administrator-proof vault. Reopening after Clear preserves
normal personal hiding across devices; permanent deletion follows site policy.

No peer notifications, calls, contact/block actions or shared pin scope apply.
Authorized file access, search, forwarding and personal pins/reactions retain
normal access checks. Disabling the feature blocks metadata, messages, files and
message-info reads; rows are retained. No production activation is included.

## Conversation media catalog (1.6.0)

`conversationMedia(User $actor, int $conversationId, string $category, ?int $beforeId=null, int $limit=30, string $query=''): array` and authenticated GET `media-catalog?id=…&category=visual|documents|audio|links&before=…&limit=30` scan normal authorized conversation history. Discovery advertises `media_catalog`; authenticated session metadata adds `media_catalog_supported`.

The response contains category, newest-first items, scanned (at most500), complete and next_before. An empty intermediate page is not the end: continue its exclusive cursor. Limits are1–50; no more than500 rows are scanned per request. Visual includes image/video MIME; audio includes voice/audio MIME; documents are other attachments; links contain HTTP(S) text only. Link detection makes no external request. Attachments still require their normal authenticated download, and returning to an original must revalidate its visibility. No new plaintext index, schema, global media count or legacy inbox behavior.

The optional catalog `q` is empty or2–128 Unicode characters and matches visible message text or attachment filenames case-insensitively. Non-string HTTP queries fail validation.

## Transient conversation activity (1.6.1)

`activityReady(): bool` requires the existing ProcessWire cache service.
`publishConversationActivity(User $actor, int $conversationId, string $kind): array`
accepts `typing`, `recording` or `none`. The authenticated actor is authoritative;
the payload contains no message, audio or user-selected sender. The result is
`{updated:true}`. Identical writes within two seconds do not renew the record.
`conversationActivity(User $actor, int $conversationId): array` returns at most20
other current members with user_id/name/title/kind/expires_in (1–8 seconds).

Authenticated GET `activity?id=…` and POST `activity` `{id,kind}` use the normal
member transport and browser CSRF. Discovery adds `activity` only when available;
session metadata adds `activity_supported`. Only active accepted conversations
participate. Saved, pending, blocked and read-only conversations produce no feed;
unauthorized conversations fail. Invisible/offline presence suppresses activity.
Group membership and version fingerprints invalidate prejoin and removed-member
records. No schema, email, push, message or audit event is created.

Cache names are keyed hashes, values are authenticated encrypted envelopes with
an eight-second logical expiry. Expired ciphertext may remain until normal
ProcessWire cache maintenance; expiry is checked on every read. No plaintext
index or draft/audio storage is introduced. Stop is best effort; expiry handles
a disconnected client. Clients must bound received lifetimes, cancel stale reads
and never invent a last-seen timestamp from these transient indicators.

The bundled web component has a per-account/browser sharing preference. The
Narzan preference is local to the device and initially enabled. Disabling one's
sharing does not prevent seeing other authorized members. Clients poll only an
open foreground conversation, throttle renewals, and stop on leave/background.
Voice recording itself is unchanged; the metadata observer never starts a mic.

## Recent direct-call events (1.6.2)

`callEventsReady()` checks existing optional signaling tables without setup.
Discovery advertises `call_events` only then. `conversationCallEvents(User $actor,
int $conversationId)` reads at most20 terminal/expired calls started in the past
24hours, newest first. REST `GET call-events?id=<positive integer>` requires the
authenticated native bearer connection; browser sessions and POST are denied.
Current actor/direct accepted active conversation and exact two participants are
validated. No calls toggle, provider configuration, device enrollment or schema
mutation is implied. Blocked chats can retain their already-known call journal.

Returns `{items:[{id,conversation_id,incoming,created_at,outcome}],window_seconds:86400}`.
Outcomes: explicit `rejected`; accepted signaling followed by end is `ended`;
unaccepted expiry is `missed`; early unaccepted termination is `cancelled`.
Direction is relative to the reader. No duration, connected-audio claim, exact
end-time, SDP/ICE, grants, tokens, names or recording leaves this route. It is
read-only even when the cleanup worker has not yet expired a row.

This is a recent call journal separate from messages, unread state and personal
message hiding; hiding a text/chat history does not erase the separate journal.
Existing signaling cleanup can remove the source rows; this route does not
extend retention, offer unlimited history/backfill or replace the seven-day
device-observed journal. Native call-back remains gated by actual opt-in,
site availability and current chat permissions. No production/store deployment.

## Native write acknowledgements (1.6.3)

Discovery advertises `write_ack`. Successful native `start`, `send` and
`send-attachment` requests with a supplied valid `client_id` return
`accepted_client_id` and Boolean `ack_visible` on the resulting message. The
core validates the original immutable operation before this correlation is
returned. An edited replay may contain the current edited body. If the message
is no longer visible to this actor, only id, conversation_id, sender_user_id,
empty body, null attachment and acknowledgement fields are returned. Clients
may remove exactly the matching confirmed queued operation, but must never
insert an invisible acknowledgement into history. GET history and browser
responses do not contain these transport fields. No schema change is needed.

## Per-message notification suppression (1.6.4 candidate)

`silentMessagesReady()` is true only with the existing explicitly installed and
enabled message tools. Discovery advertises `silent_messages`; browser session
metadata includes `silent_messages_supported`. No schema is installed by a read.
Existing marker rows are reused, including after tools are disabled. No new table
or production migration is required. Include `src/MessengerSilent.php` in any
future pinned deployment manifest; this source candidate is not installed.

`startConversation(..., array $context = [], bool $silent = false)`,
`sendMessage(..., bool $silent = false)` and
`sendAttachment(..., bool $silent = false)` accept an explicit per-message choice.
REST start/send/send-attachment accept a strict JSON Boolean `silent` (default
false). Ordinary request hashes remain unchanged. Quiet hashes bind the mode;
retrying an ID with another notification intent returns409 instead of duplicating
or changing the original. Existing permissions and current membership still apply.

Quiet messages stay visible and unread under normal authorization; they are not
avatars, global mutes or hidden messages. They enqueue neither email nor message
push; both workers recheck suppression before a provider call. Ordinary later
messages and recipient preferences are unchanged. Authorized message/inbox
metadata carries `silent`; a quiet native write receipt additionally carries
`accepted_silent: true`, even when current content has been redacted.

The bundled browser attachment preview offers a per-upload choice, preserving
that choice with its immutable retry payload. Updated clients must use the flag
to suppress their own foreground alerts as well. Older clients or third-party
site code that ignore metadata may generate local alerts; do not promise updated
client behavior merely from installing the module. No real provider was enabled
by this development work.


## Group polls (1.6.5 development candidate)

Backend/API and capability-gated web controls; Narzan integration has its own native acceptance record.
Requires existing enabled groups and an explicit administrator call to
`installGroupPolls(User $actor)`. This creates `messenger_polls`,
`messenger_poll_votes` and `messenger_poll_locks`; no member/discovery migration.
The additional `src/MessengerPolls.php` runtime must be pinned in consuming sites.
`pollsReady()` and discovery `group_polls` / session `polls_supported` are capability
checks, not evidence of production installation.

- `createGroupPoll(User $actor,int $id,string $question,array $options,string $clientId)`:
  one-line question1–240 characters,2–8 distinct one-line options1–100 characters.
  Its normal encrypted message body contains the question and numbered options for
  older clients. Options also have an encrypted structured representation. The
  existing send policy, limits, idempotency and notifications apply. Never use an
  ordinary/attachment operation's ID. Return is `message_id`, `conversation_id`, `accepted_client_id`,
  `ack_visible`, and nullable `poll`; a hidden accepted replay reveals no poll.
- `groupPoll(User $actor,int $messageId)`: current membership and message visibility
  apply, including new-member floors, personal hiding, deletion/moderation/expiry.
- `voteGroupPoll(User $actor,int $messageId,?int $choice)`: exact desired zero-based
  option or explicit null to retract. One vote per member, changeable until close.
- `closeGroupPoll(User $actor,int $messageId)`: author or group owner/admin, permanent.
  An identical accepted vote/close can be retried after close; changes are denied.

REST routes: `POST group-poll-create` with `id,question,options,client_id`;
`GET group-poll?message_id=…`; `POST group-poll-vote` with `message_id,choice`;
`POST group-poll-close` with `message_id`. Browser writes require normal CSRF;
actor/roles never come from request JSON.

Authorized message history adds `poll`: `message_id,options,counts,total,own_choice,
closed_at,created_by,anonymous:true`. No voter list is returned. Historical anonymous
votes remain in aggregates after departure/hiding; the former member loses access.
Joining later does not grant access to an earlier poll. Individual choices are
encrypted at rest, but this is server-readable encryption, **not E2EE or anonymity
from the site operator**. Poll bodies cannot be edited into inconsistent ballots;
forwarding copies ordinary text, not a live shared poll. New effective changes
share a serialized60/minute member limit; exact desired-state retries are free.
Conversation locking precedes group/poll row locking, and state is reread with
current locking reads so a blocked voter cannot vote after a concurrent close.

Group vote/close events advance the group message revision. Global message deletion or expiry erases structured options and individual choices; only an immutable operation tombstone is retained for a redacted acceptance receipt. See [poll verification](docs/POLLS.md).

## Group invitation links (1.6.6 development candidate)

See the [complete group link contract](docs/GROUP_LINKS.md). Explicit administrator
`installGroupLinks(User)` migration is required. Public methods are
`groupMessagesPath(): ?string`, `groupLinksReady(): bool`,
`groupLinkStatus(User,int)`, `createGroupLink(User,int,int,int,string)`,
`revokeGroupLink(User,int,int)`, `previewGroupLink(User,string)` and
`joinGroupLink(User,string)`. Current actor authorization is mandatory. Share token
fragments only; use authenticated POST for preview/join. No anonymous membership,
member-side schema creation or automatic joining is provided.

## Group mentions (1.6.7 development candidate)

See [the operator, privacy and transport contract](docs/MENTIONS.md). Public methods:
`installGroupMentions(User $administrator)`,
`setGroupMentionMode(User $actor, int $id, bool $mentionsOnly)` and
`groupMentions(User $actor, int $id, ?int $before = null)`.
Discovery `group_mentions` and browser `mentions_supported` require installed schema.
Messages add actor-specific `mentioned` and encrypted-at-rest captured `mentions`
entities; group summaries add own `mentions_only` and independent unread
`last_mention`. No template-side SQL, recipient impersonation or automatic migration.

## Group events (1.7.0 development candidate)

See [the event, consent and operator contract](docs/GROUP_EVENTS.md). Public methods:
`installGroupEvents`, `createGroupEvent`, `groupEvent`, `updateGroupEvent`,
`respondGroupEvent`, `cancelGroupEvent`, `processGroupEventReminders`.
Message `event` is actor-specific structured state; `group_events` and separate
`group_event_reminders` capabilities gate controls. Response versions, current
membership/visibility and per-member consent are mandatory. No automatic schema,
worker/provider installation, browser-push registration or public attendee list.


## Native client diagnostics (1.7.2 candidate)

Separate explicit operator installation: `installClientDiagnostics($admin)`. Discovery
advertises `client_diagnostics` and call-config reports `diagnostics_enabled` only
after that schema exists. Installation is never triggered by native requests or upgrades.

`POST client-diagnostics/` requires a current native Bearer grant and body
`{version:1, platform:"ios"|"android"|"other", enabled:true, events:[...]}`.
At most50 events/64KiB. Each event has immutable32hex `id`, enumerated `name`,
`time_ms` (last24h, up to5min clock tolerance), optional enumerated `screen`,
positive `chat_id`, UUID `call_id`, and up to8 whitelisted numeric `values`.
Unknown keys/text metrics are rejected. No message, audio, keyboard, coordinates,
URL, IP, device identifier or native exception payload is accepted.
The accepted list must exactly acknowledge the batch. Reuse with altered data is409.
Chat membership and the call's exact native grant are checked again on every write.
Encrypted at rest with authenticated event/grant/member context. Maximum5000 new
events/member/day and100000 total. Seven-day retention is pruned on ingestion,
private export and the existing message-push worker; keep that worker scheduled.
Private `clientDiagnostics($admin,$cursor,100)` additionally requires export rights
and returns a composite cursor on each row. It never makes a public feed.

Default-on client collection is separately explained and switchable. Opt-out clears
unsent local data and cancels upload; already accepted packets expire on the server.
The existing optional30-day call-rating survey retains its separate explicit consent.
Do not deploy this candidate without a consuming-site backup and operator approval.
