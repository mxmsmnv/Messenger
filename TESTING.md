# Messenger testing plan

## Scope

- Project: Messenger
- Classification: C — Process and user-facing module
- Test owner: module maintainer
- Supported ProcessWire versions: 3.0.200+
- Supported PHP versions: 8.1+
- Uninstall data policy: preserve-user-data

## Risk summary

Messenger stores private encrypted content, exposes a same-origin member API, applies participant and moderation permissions, sends optional email, and can create administrative broadcast messages for a snapshotted audience. Highest-risk boundaries are authorization, CSRF, encryption, duplicate delivery, queue retries, evidence access, and recipient selection.

## Test commands

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/contracts.php
php tests/encryption-integration.php --root=/path/to/processwire
php tests/broadcast-integration.php --root=/path/to/processwire
```

JavaScript is shipped without a build step; validate it with `node --check assets/messenger.js` when Node.js is available.

## Test environment

- Development site: `https://lqrs.test/`
- Database isolation: dedicated local development database; integration tests create uniquely prefixed users and roles and remove them in `finally`
- Administrator account: existing local superuser
- Member accounts: `E2E` browser fixtures plus unique integration fixtures
- External service fakes: email remains disabled or uses a local mail catcher
- Fixture prefix: `E2E` and `messenger-*-test-*`

## ProcessWire boundary coverage

- [x] Module discovery and metadata
- [ ] Fresh install on a disposable database
- [x] Configuration defaults and save
- [x] Dependencies
- [x] Public API and documented hooks
- [x] Permissions and roles
- [x] Data save and reload
- [x] Upgrade from the currently installed prior version
- [x] Uninstall policy declared as preserve-user-data
- [ ] Reinstall on a disposable database

Fresh install and reinstall remain release-candidate checks because they require a disposable ProcessWire database.

## Critical automated journeys

### Encrypted direct messaging

- Role: two fixture members
- Starting state: no conversation
- Actions: create, read, search, report, tamper, and edit a message
- Expected UI/API result: authorized plaintext round-trips; tampering fails closed
- Expected stored result: message and report content use authenticated-encryption envelopes
- Access/security assertion: only participants can read
- Cleanup: remove conversation, report, audit, outbox, and fixture users

### Administrative broadcast

- Role: superuser sender and two members in a unique fixture role
- Starting state: no broadcast or sender-recipient conversations
- Actions: preview role audience, create encrypted broadcast, process batch, replay batch
- Expected UI/API result: exactly two recipients selected and delivered once
- Expected stored result: encrypted source body, sent delivery rows, one message per recipient
- Access/security assertion: ordinary user is denied broadcast preview
- Cleanup: remove delivery, broadcast, conversation, message, audit, user, and role fixtures

## Agent-led release scenarios

### Administrator session

- [x] Preview all-member and role audiences
- [x] Confirm and create a broadcast
- [x] Inspect progress, history, detail, and completion states
- [ ] Inspect cancellation state
- [ ] Verify unauthorized moderator access is denied

### Member session

- [x] Receive the broadcast as a normal accepted conversation through the member API
- [ ] Verify unread state and reply behavior

### Presentation

- [x] Desktop viewport
- [ ] Narrow mobile viewport
- [x] Keyboard/focus and accessible names smoke check
- [ ] Browser console and failed network requests inspected

## Failure paths

- [x] Invalid or empty audience
- [x] Unauthorized actor
- [x] Duplicate batch replay
- [x] Queue retry cap and deterministic delivery ID
- [x] Unavailable or restricted recipient skip
- [x] Empty audience
- [ ] CSRF failure in a real admin session
- [ ] Large audience batch continuation
- [x] CLI-disabled execution in the consuming site
- [ ] CLI dry-run with the worker enabled
- [x] Audit records contain identifiers and counts, not message bodies

## External services

| Service | Test substitute | Live test policy |
|---|---|---|
| WireMail | disabled transport or local catcher | no real email during ordinary tests |

## Cleanup

- Integration scripts remove their uniquely named fixtures in `finally`.
- Browser fixtures must be recorded and removed or intentionally retained.
- No broadcast test may target an unscoped real-member audience.
- Restore any changed Messenger configuration after browser testing.
