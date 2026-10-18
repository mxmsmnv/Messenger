# Messenger public API

This document describes Messenger 1.3.0 (ProcessWire module version `130`). Confirm the installed version and live site configuration before using it; documentation is not evidence that the module is installed or enabled.

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


## Narzan member features (1.3.0)

Discovery advertises `features:["presence","directory","attachments","read_receipts"]`. Clients must treat missing features as unsupported and preserve compatibility with older servers.

All routes below use the existing `/messenger-api/v1/{resource}/` transport and its cookie/CSRF or native bearer authentication. They do not enable mobile identity automatically.

- `GET presence`: own `{status,text,expires_at}`; `expires_at` is Unix seconds. `POST presence {status,text}` selects `online`, `away`, `busy`, `invisible` or `offline` and refreshes a 90-second heartbeat. Status text is at most 120 characters, encrypted at rest. `POST presence {heartbeat:true}` renews only activity expiry, retaining the latest server status/text across devices; an absent row initializes online with empty encrypted text. Heartbeats cannot include status or text. Clients must use heartbeat mode for periodic renewal rather than replaying cached preferences. Owner preference survives expiry; peers see expired/invisible/offline as exactly `{status:"offline",text:"",expires_at:0}`.
- `GET directory?q=&limit=30`: eligible members, bounded to 30; empty query provides the default directory. `GET contacts` uses existing conversation peers. Both exclude self, either-direction blocks and restricted recipients. Directory is a member discovery surface, not an external directory or address-book upload. Responses are member objects with `presence`.
- Conversation objects add integer `other_last_read_message_id`; blocked/restricted peers expose zero. This is a read watermark, not a device-delivery receipt. A successful send is server acknowledgement; a peer read cursor at or above the message ID is read.
- `POST send-attachment {id,client_id,body?,filename,mime_type,kind,data_base64,duration_ms?}` atomically creates a message plus encrypted attachment. Existing accepted conversations only; no files in message requests. `kind` is `file` or `voice`; maximum decoded sizes are 8 MiB and 4 MiB respectively. Empty files are rejected, filenames are bounded to 180 characters and cannot contain paths/control characters. Voice requires a declared and detected supported audio/container MIME; duration is optional 1–120000 milliseconds (client supplied, not independently measured). Generic files use the detected MIME and are treated as opaque bytes. The same client ID binds the entire canonical payload, including bytes, name, kind and duration; conflicts return 409. Replays produce one message and one attachment.
- Message objects optionally add `attachment:{id,filename,mime_type,size,kind,duration_ms}`. `GET attachment?id=123` returns this object plus `data_base64`, only to an eligible unblocked participant in the accepted active conversation while the message remains visible and undeleted. No permanent/public download URL exists. Clients must write files to private storage and share through explicit platform mechanisms; do not render HTML/SVG as application content or attach bearer tokens to external URLs.

The JSON request body limit is 12 MiB only for `send-attachment` (64 KiB elsewhere). Configure the consuming HTTP server/PHP body limits to allow that request size. Encrypted metadata is stored separately, so ordinary thread polling never selects or decrypts attachment bytes. Downloads are JSON base64; clients should bound responses to 12 MiB. Transfer is memory bounded by the stated limit, not streaming/resumable. These limits are per attachment, not a total user or installation storage quota. Before activation, the site owner must provision storage capacity and choose retention/deletion policy. The 120-second duration field validates client metadata; server-side codec duration parsing is not implemented, so this is not a server-enforced media duration guarantee. Audio transcription, virus scanning, push, delivery-to-device receipts and media retention/purge jobs are not implemented. Deleted/hidden media is denied at download, and encrypted storage is retained consistently with existing module uninstall behavior. Existing web clients display the caption/filename fallback; their attachment controls are unchanged.

Public PHP methods: `presence(User)`, `heartbeatPresence(User)`, `setPresence(User,string,string='')`, `memberDirectory(User,string='',int=30,bool=false)`, `sendAttachment(int,User,array,string)`, and `attachment(User,int)`. They enforce the same domain authorization as REST. Existing `sendMessage` signature is unchanged.
