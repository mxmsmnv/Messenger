# Account-bound message notification candidate

Messenger1.6.9/source only. Nothing is deployed or enabled on a consuming website.
The native Narzan account runtime/chooser and inactive push/call routing are not
complete. Ordinary current clients still use legacy registration.

## Compatibility and storage

An authenticated native grant may explicitly set `account_binding:true` on the
existing message-device endpoint after discovery advertises `account_push`. The
response confirms that mode and the current connection ID; the request cannot
choose a user or grant. Legacy bodies/responses and token-transfer behavior remain
unchanged. Explicit bindings do not transfer or disable other account bindings.
Selective disable/revocation affects one grant only.

No schema change: reuse the unique token_hash column with SHA256 over a fixed
domain separator, platform, grant and token, separated with NUL bytes. Provider
tokens remain in the existing authenticated-encryption envelope/AAD. A worker
verifies the current decrypted registration against legacy or account-bound hash
mode before routing. Explicit mode never performs an unrelated-grant transfer
UPDATE, avoiding an unnecessary absent-key/gap-lock interaction during concurrent
registrations. Legacy registration still transfers only a legacy hash.

## Notification boundary

For opted-in grants, accountKey is `issuer#siteId#memberId#connectionId`; the old
three-segment key remains exact for legacy registrations. Message and consented
event-reminder provider callback signatures remain six/seven arguments. Generic
alerts, mute/silent/read/hide/expiry/current-membership checks, bounded queues,
provider rejection and worker-user restoration remain in force. AccountKey is
routing metadata, not an access credential. No message text is added to providers.

Clients must resolve only an existing stored connection with server-confirmed
member identity, never a URL or the selected account as fallback. They must still
fetch/recheck visibility through that owned account before opening the message.
An ambiguous legacy identity must be refused. This capability does not supply
account-bound VoIP invitations or activate multi-account UI by itself.

## Verification

Full `tests/run.sh` passes, including syntax/contracts/encryption and182HTTP
transport checks with task-created server cleanup. New SQLite checks:22account
message cases and4account event-reminder cases. Legacy29message-push and20event-
reminder cases pass unchanged. Patch upgrades through169 remain service/DDL-free;
future/repeated/older upgrade behavior is retained.

Real isolated ProcessWire3.0.255/MySQL clone:15account message/reminder checks plus
one two-process concurrent-registration check, with12inherited forwarding checks.
Same-token grants survive the actual unique index; token encryption, legacy
transfer, exact connection metadata, selective disable/revoke, two consented
reminders and concurrent processes pass. Provider is a synthetic callback only;
no real APNs/FCM, live website or production data is touched. Owned clone/database
are removed and MySQL stopped. Evidence private `.work/account-push-regression3.log`,
`account-push-mysql3.log`, and Narzan `.work/accounts/push-real-fixture/`.

Source candidate acceptance is distinct from site deployment, physical-device
push delivery, account chooser/callback/call routing and store release.
