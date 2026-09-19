# Messenger

Private, safety-aware member messaging for ProcessWire, with Message Requests, moderation, encrypted storage, and a ready-to-theme frontend application.

![Messenger](assets/Messenger.png)

Use Messenger when a ProcessWire site needs direct one-to-one conversations without handing trust policy, moderation, or private data to a third-party chat service. The module owns messaging state and safety controls; the site keeps ownership of profiles, matches, connections, page composition, and visual framework assets.

- Author: [Maxim Semenov](https://github.com/mxmsmnv)
- Website: [smnv.org](https://smnv.org/)
- Email: `maxim@smnv.org`

If this module saves you time, you can [sponsor the work](https://smnv.org/sponsor/) or use [GitHub Sponsors](https://github.com/sponsors/mxmsmnv).

## Features

- One unique direct conversation per pair of ProcessWire users.
- Configurable first contact: open, Message Requests, or a site-owned policy hook.
- Request counts, accept/decline, pending-send limits, unread watermarks, archive, mute, and notification preferences.
- Edit, delete-for-everyone, hide-for-self, block/unblock, and report actions with enforced ownership and time windows.
- Cursor pagination, idempotent message creation, recipient search, and bounded encrypted-message search.
- Per-member rate limits, temporary restrictions, immutable report evidence, and audited moderation decisions.
- Separate permissions for moderation, evidence viewing, administration, export, and deletion boundaries.
- Same-origin JSON API with ProcessWire sessions, CSRF validation, private headers, and no CORS opt-in.
- XChaCha20-Poly1305 authenticated encryption at rest for messages and report content.
- Selectable WireMail provider and preview-first local notification worker.
- Responsive member UI plus an AdminThemeUikit dashboard, report queue, restrictions workspace, and settings navigation.
- Presentation adapters for semantic styles, Designsystemet, UIkit, Bootstrap, Tailwind CSS, and custom role mappings.

## Requirements

- ProcessWire 3.0.200 or newer
- PHP 8.1 or newer
- PHP sodium extension
- An installation-specific `$config->tableSalt`, or preferably a dedicated `$config->messengerEncryptionKey`

## Installation

1. Copy `Messenger` to `site/modules/`.
2. In ProcessWire, refresh modules and install **Messenger**.
3. Grant `messenger-moderate` only to trusted moderators. Grant `messenger-view-content` separately to staff allowed to inspect preserved evidence.
4. Review first-contact, rate-limit, frontend, email, and retention settings.
5. Create the site's messages page/template using [EXAMPLES.md](EXAMPLES.md).
6. Hook `Messenger::messagingDecision` when the site's verified match or connection rules should decide first contact.

Fresh installation creates no sample conversations and grants no role permissions. Email delivery is disabled by default. Uninstall retains Messenger tables and permissions; private data is never silently purged.

## Site integration

The consuming site owns the route, navigation, profile links, verified relationship facts, and shared-cache exclusions. Messenger supplies the application and enforces actor, participant, block, restriction, request, and rate policies:

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Messenger')) {
    /** @var Messenger $messenger */
    $messenger = $modules->get('Messenger');
    echo $messenger->renderApp($user);
}
```

Exclude the messages page and `/messenger-api/` from shared caches. Messages are plain text and must never be rendered as trusted HTML.

For connection- or match-only messaging, return `allow`, `request`, or `deny` from the hookable policy after consulting the site's authoritative service:

```php
$wire->addHookAfter('Messenger::messagingDecision', function(HookEvent $event) {
    [$actor, $recipient] = $event->arguments();
    if(!$event->wire()->matches->isVerifiedPair($actor, $recipient)) {
        $event->return = ['decision' => 'deny', 'reason' => 'Messaging requires a verified match.'];
    }
});
```

## Frontend frameworks

The **Frontend framework** setting adds safe classes and `data-*`/ARIA attributes to stable Messenger UI roles. The site remains responsible for loading its selected framework stylesheet; Messenger never enqueues third-party framework assets. A custom JSON adapter can define or override role attributes:

```json
{"input":{"class":"my-input"},"button_primary":{"class":"my-button","data-variant":"primary"},"badge":{"class":"my-badge"}}
```

## Notifications

Messenger can follow the site-wide WireMail transport or use a specific installed WireMail module. Provider credentials remain in that provider's configuration and are not copied into Messenger.

Preview queued delivery before executing it from local cron:

```bash
php site/modules/Messenger/bin/messenger notifications --root=/path/to/site --dry-run
php site/modules/Messenger/bin/messenger notifications --root=/path/to/site --execute
```

## Security and data lifecycle

Message bodies and report comments, evidence, and resolutions use versioned authenticated-encryption envelopes. The key is domain-separated from `$config->messengerEncryptionKey`, or from `$config->tableSalt` as a fallback, and is never stored in Messenger tables. Do not change that secret without a verified backup and an explicit key-rotation migration: existing ciphertext would become unreadable.

Message search decrypts and scans at most the newest 1,000 accessible messages because randomized ciphertext cannot be queried with SQL `LIKE`. Moderators do not gain conversation content from `messenger-moderate`; evidence access requires `messenger-view-content`.

Retention settings describe policy only. Permanent purge, anonymization, export, and key rotation remain explicit high-risk site operations.

## Documentation

- [API.md](API.md) — supported PHP and REST interfaces
- [EXAMPLES.md](EXAMPLES.md) — site integration examples
- [AGENTS.md](AGENTS.md) — Olivia and coding-agent safety guidance
- [CHANGELOG.md](CHANGELOG.md) — release history

## License

[MIT](LICENSE) © 2026 Maxim Semenov.
