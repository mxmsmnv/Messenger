# Messenger public API

This document describes Messenger 1.0.0 (ProcessWire module version `100`). Confirm the installed version and live site configuration before using it; documentation is not evidence that the module is installed or enabled.

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

## Configuration

Defaults are returned by `Messenger::getDefaultConfig()`: public path `/messages/`, Designsystemet adapter, Message Requests first-contact mode, 5,000-character messages, rate limits, 15-minute edit/delete windows, 4/20-second polling, email and CLI disabled, and retention disabled. Treat live ProcessWire module settings as authoritative.

## Internal and lifecycle boundaries

`handleRestRequest()`, `getModuleConfigInputfields()`, `install()`, `upgrade()`, and `uninstall()` are framework lifecycle/transport entry points, not site-domain APIs. Database helpers, encryption helpers, audit helpers, `MessengerRestApi`, and `ProcessMessenger` internals are unsupported.

Do not query or write `messenger_*` tables, expose `direct_key`, client IDs, audit metadata, or evidence snapshots, or call private/protected methods. Uninstall retains tables and permissions. There are no deprecated public APIs in 1.0.0.
