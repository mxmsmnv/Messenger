# Messenger public API

This document describes Messenger 1.4.5 (ProcessWire module version `145`). Confirm the installed version and live site configuration before using it; documentation is not evidence that the module is installed or enabled.

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

The JSON request body limit is 12 MiB only for `send-attachment` (64 KiB elsewhere). Configure the consuming HTTP server/PHP body limits to allow that request size. Encrypted metadata is stored separately, so ordinary thread polling never selects or decrypts attachment bytes. Downloads are JSON base64; clients should bound responses to 12 MiB. Transfer is memory bounded by the stated limit, not streaming/resumable. These limits are per attachment, not a total user or installation storage quota. Before activation, the site owner must provision storage capacity and choose retention/deletion policy. The 120-second duration field validates client metadata; server-side codec duration parsing is not implemented, so this is not a server-enforced media duration guarantee. Audio transcription, virus scanning, push, delivery-to-device receipts and media retention/purge jobs are not implemented. Deleted/hidden media is denied at download, and encrypted storage is retained consistently with existing module uninstall behavior. Existing web clients display the caption/filename fallback; their attachment controls are unchanged.

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

## Optional message information (1.4.5)

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

Only exact 143→145 and 144→145 patch upgrades skip schema/permission work; the
historical exact 143→144 no-op remains. Older, repeated and future lifecycle paths
retain their existing behavior. Version 1.4.3/1.4.4 clients can keep their existing
sent/read cursor status and show that exact read time is unavailable.
