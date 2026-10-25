# Account-bound call invitation candidate

Messenger1.7.0/source only; no live deployment, schema/provider change or native
multi-account activation. Existing four-argument legacy call delivery remains
compatible. Read API.md and docs/ACCOUNT_PUSH.md for the separate message path.

## Explicit registration

`accountCallsReady()` is read-only and checks existing call configuration/schema;
discovery adds `account_calls` and call-config adds `account_binding_supported`
only for that ready source. An existing authenticated grant may add boolean
`account_binding:true` to call-device. The acknowledgement binds mode and the exact
connection ID. The client must require that acknowledgement, never fall back to
legacy enrollment after a missing/foreign response.

No new table/column: the existing encrypted token uses authenticated AAD
`Messenger|call-device-account|grant`. Legacy registration keeps
`Messenger|call-device|grant`. The worker first authenticates bound mode, then tries
the legacy context. Empty/corrupt/moved ciphertext cannot produce a provider token.
A legacy plaintext token that resembles JSON or a prefix cannot impersonate mode.
The application encryption key/algorithm/envelope is unchanged.

## Dispatch

Before delivery, recheck caller grant, current caller/recipient conversation
policy, exact recipient device/grant owner, revocation, enabled state and age.
Read the current encrypted token/platform so rotation does not use a stale row.
Restore worker user context on both caller and recipient paths. A non-boolean
provider response is a bounded failure, never confirmed delivery.

A bound provider callback receives `(platform,token,callId,callerHint,accountKey)`;
legacy remains four arguments. Custom providers must consume the fifth argument
for account mode. The built-in APNs/FCM sender places `narzan_account` alongside
kind/call UUID/caller hint. AccountKey is `issuer#siteId#memberId#connectionId`,
metadata rather than authority. Generic call signaling/media credentials/message
text are not included. VoIP topic/type, deadlines and existing delivery retry
bounds remain in force. Missing scope must not choose the currently selected
account in a multi-account client.

Actual clone testing exposed pre-existing MySQL collation differences between
mobile grant and call tables. Grant joins/expiry comparisons now use byte-exact
MySQL comparisons (SQLite retains its original SQL), with no table ALTER or data
rewrite. This preserves opaque identifiers across historical collations.

## Rollback boundary

An older worker cannot authenticate a newly account-bound AAD envelope. Before
rolling back an activated site/client, disable the new native enrollment and
re-register affected grants in legacy mode or restore matching encrypted device
rows from the verified backup. Do not change the encryption key to address a
format mismatch. Source-only verification is not a completed rollout/rollback.

## Verification and limits

Full regression passes, including calls, feedback, call journal, message/group
paths and182HTTP checks;231additive upgrade checks are service/DDL-free. New
SQLite23account-call checks and26fake HTTPS/provider checks pass. Provider tests
exercise bound FCM and APNs VoIP payload/topic/type, legacy payload preservation
and malformed scope refusal without contacting Apple/Google. Synthetic signing
files are cleaned by the test.

Owned actual ProcessWire3.0.255/MySQL clone:12new account-call checks plus12inherited
forwarding checks pass. Bound/legacy registrations, distinct authenticated AAD,
current token rotation, exact recipient scope, four/five-argument callbacks,
worker-context restoration and revoked caller pass. The original mixed-collation
failure is retained; the corrected clone completes. Database dropped/MySQL stopped.
Evidence private `.work/account-calls-*` and Narzan `.work/accounts/call-real-fixture/`.

Narzan typed API and stored-account resolver are source components. Native iOS/
Android call ownership, inactive registration restoration, PushKit/CallKit/FCM
lifecycle, chooser and real physical closed-app audio acceptance remain required
before activating multi-account. Current entrypoints remain single-account and
legacy; no physical-device or acoustic acceptance is claimed by these tests.
