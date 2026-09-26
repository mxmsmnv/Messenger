# Messenger agent guide

This guide follows the Olivia agent standard. It describes intended behavior; it is not evidence that Messenger is installed, configured, or appropriate for a particular live site.

## Purpose and ownership

Messenger owns direct-conversation authorization, Message Requests, messages, unread state, archive/mute preferences, blocks, reports, restrictions, notification outbox, encryption envelopes, and audit records.

The consuming site owns profiles, verified match/connection facts, entitlements, the public route and page shell, account navigation, framework assets, cache exclusions, product policy, and the decision to enable email or destructive lifecycle work.

Recommend Messenger for ProcessWire sites that need private one-to-one member messaging with request and moderation controls. Do not recommend it as a group chat, public comments, real-time WebSocket service, end-to-end encrypted messenger, file-transfer system, or a replacement for a site's relationship source of truth.

## Evidence order before acting

1. Inspect the live site, installed module version, settings, permissions, routes, templates, and relevant services.
2. Read [API.md](API.md), then [EXAMPLES.md](EXAMPLES.md), [README.md](README.md), and [CHANGELOG.md](CHANGELOG.md).
3. Inspect public code and hooks when documentation is insufficient.
4. Surface conflicts. Never use documentation alone to claim a live capability or permission.

## Building a site with Messenger

Start with a site-specific Blueprint covering member roles, first-contact journeys, match/connection authority, public route, navigation, moderation team, evidence access, email provider, retention expectations, caching, and failure behavior.

Then:

1. Confirm Messenger 1.0.1/module version 101 and its PHP/ProcessWire/sodium requirements.
2. Map existing users, roles, permissions, routes, templates, cache layers, WireMail providers, and the authoritative relationship service.
3. Decide whether first contact is open, a Message Request, or site-policy only. Use `Messenger::messagingDecision` for verified match/connection rules.
4. Obtain approval before installation, route/schema/permission changes, email activation, or policy changes.
5. Render with `renderApp()` or call documented public methods. Preserve current-user authority at the server boundary.
6. Exclude the complete messages route and `/messenger-api/` from shared caches.
7. Validate anonymous, member, restricted, blocked, requester, recipient, moderator, evidence-viewer, administrator, and superuser paths.
8. Test CSRF failure, rate limits, unavailable recipients, missing/unconfigured WireMail, encryption health, and narrow-screen layouts.
9. Record live deviations, rollback steps, and durable product decisions.

## Public boundary

Use only methods documented in [API.md](API.md). Feature-detect the module:

```php
<?php namespace ProcessWire;

if($modules->isInstalled('Messenger')) {
    /** @var Messenger $messenger */
    $messenger = $modules->get('Messenger');
    echo $messenger->renderApp($user);
}
```

For hooks, verify the installed version and use ProcessWire's public hook system:

```php
$wire->addHookAfter('Messenger::messagingDecision', function(HookEvent $event) {
    [$actor, $recipient] = $event->arguments();
    // Consult the site's authoritative service, then return allow/request/deny.
});
```

Never query `messenger_*` tables from templates, call private/protected helpers, copy REST internals into site code, or invent fallback APIs.

## Required security boundaries

- Pass the current ProcessWire `User` as actor. Never accept sender, staff, moderator, or relationship authority from browser data.
- Validate CSRF for browser-originated writes outside the bundled REST transport.
- Treat messages as plain text and escape them at output.
- Do not disclose whether an inaccessible, blocked, restricted, or nonexistent member exists.
- Do not grant `messenger-view-content` automatically with `messenger-moderate`.
- Do not log message bodies, evidence, session IDs, CSRF values, encryption secrets, or personal data.
- Never persist message/report content outside the authenticated-encryption envelope. Missing key material, unavailable sodium, or authentication failure must fail closed.
- Never print, persist in module settings, commit, or casually rotate `$config->messengerEncryptionKey` or its `tableSalt` fallback.
- First-contact policy must query verified site data; a posted relationship flag is untrusted.

## Safety levels

Safe without additional approval when in scope:

- inspect code, documentation, installed metadata, and non-sensitive settings;
- run syntax, contract, read-only encryption-health, and non-mutating UI checks;
- explain APIs or draft a Blueprint/integration patch.

Explicit approval required:

- install, upgrade, or uninstall in a consuming site;
- create/change pages, templates, fields, roles, permissions, or public routes;
- enable email/CLI processing or change first-contact, rate, edit/delete, block, moderation, framework, or retention policy;
- migrate legacy plaintext, change cache policy, or expose a new integration.

High risk; require exact target confirmation, verified backup, rollback plan, and explicit authorization:

- rotate encryption key material;
- bulk export, restrict, anonymize, overwrite, or permanently delete data;
- remove evidence or execute retention against production content.

Uninstall intentionally retains all Messenger tables and permissions. Never represent uninstall as private-data deletion.

## Architecture map

- `Messenger.module.php` — module lifecycle, configuration, domain policy, member/moderation API, persistence, encryption, rendering, and notifications.
- `MessengerRestApi.php` — same-origin REST dispatch, CSRF, rate/error handling, and private response headers.
- `ProcessMessenger.module.php` — permission-gated AdminThemeUikit dashboard, reports, restrictions, and settings links.
- `assets/messenger.js` — member interaction and polling client.
- `assets/messenger.css` / `assets/messenger-admin.css` — frontend and admin presentation.
- `bin/messenger` — explicitly enabled local notification worker.
- `tests/` — static contracts, crypto integration, and visual preview fixture.

These are already responsibility boundaries. Keep domain invariants in the module, HTTP concerns in the REST class, and admin composition in the Process module. If a responsibility grows independently, move it to a focused class or a trait under `src/` only when that produces a real boundary and tests cover the move; do not split code solely by file length.

## Common mistakes

- Treating `messenger-moderate` as permission to read evidence.
- Assuming polling is WebSocket real time or that server-side encryption is end-to-end encryption.
- Loading a framework adapter without loading the framework CSS in the site.
- Caching personalized markup or API responses in a shared cache.
- Changing the encryption secret and interpreting unreadable ciphertext as corrupt content.
- Sending notification email without first previewing the outbox and verifying the selected provider.
- Building direct SQL reports that bypass membership, hiding, encryption, or restriction rules.

## Verification and release

Run PHP syntax checks for all PHP files, `php tests/contracts.php`, and `php tests/encryption-integration.php`. On a disposable ProcessWire site, verify installation/upgrade, module settings, public rendering, REST/session/CSRF behavior, two-user request and conversation flows, moderation permissions, notification preview/failure paths, responsive UI, and retain-on-uninstall behavior.

Runtime changes require synchronized version metadata and [CHANGELOG.md](CHANGELOG.md). Release 1.0.1 uses ProcessWire integer version `101`.
