# Call reliability candidate — 1.7.1

This is a local candidate, not proof of production deployment or acoustic acceptance.
Existing websites, grants, browser assets and call provider keys are preserved.

## Explicit operator upgrade

Back up the complete site database, module runtime and private provider configuration;
verify a restore in an isolated database. Deploy only a reviewed committed runtime.
The ordinary 143–171 metadata upgrade path deliberately performs no schema work.
An administrator must run `MessengerMobileAuth::install($database)` and
`$messenger->installExperimentalCalls($administrator)` from a controlled site CLI.
Both calls are idempotent. The only new tables are `messenger_mobile_receipts`
and `messenger_call_relays`; existing messages, users and permissions are not changed.
Do not call setup from a member request or a web template.

Rollback: restore prior runtime and its exact pin. New tables can remain unused;
do not drop live grants or tokens. Never restore an old token snapshot over active
rotations. Remove any queued new client transactions only by explicit reauthorization.

## Relay pool

Under Narzan mobile access, select Update call relay pool. Up to five named coturn
servers accept up to four TURN/TURNS URLs each. Each server uses its own coturn REST
shared secret (32–256 characters). Leave a password empty to retain that slot’s
secret. Remove its URLs to remove that slot. All empty explicitly selects direct-only.
Without an explicit saved pool, the existing private `messengerCalls.turn_urls` and
`turn_secret` configuration remains in use. Secrets and URLs are sealed in a dedicated
table; transient form fields are stripped before ordinary module configuration saves.
Only ephemeral grant/call-bound HMAC credentials are returned to accepted participants.

`call-ice` adds `ice_servers` and retains the first server’s legacy fields. A new client
can accept an empty pool for direct ICE. TURN is a fallback for NAT/firewalls; direct
mode cannot promise connectivity across restrictive networks. Optional STUN/STUNS discovery uses the operator’s existing coturn by default,
with no silent third-party provider. An explicit pool may set discovery URLs or
disable discovery. STUN finds public candidates and never relays audio.
A second URL on the same
VPS is not infrastructure redundancy. Do not advertise separate geographic relays
without actual separately operated servers and verified relay traffic.

Calls still require explicit operator enablement and configured push delivery for
closed-app incoming calls. TURN provisioning is independent of push provisioning.

## Refresh recovery

Discovery advertises `refresh_recovery` only after receipt schema is installed.
A supporting client seals a random immutable `recovery_id` alongside the old refresh
credential before sending. Exact retries return the same encrypted replacement receipt
for at most 600 seconds, with remaining access lifetime. Revoked grants, expired
receipts and already-consumed replacement credentials cannot be recovered. A different
nonce on a consumed token keeps the existing grant-revocation behavior. Legacy refresh
requests remain one-use. No receipt/token/key/nonce is logged or returned in diagnostics.

## Recovery and time

Active participants can send `restart`; only the original caller can offer SDP, and
only the bound answering device can answer. Existing access, block and grant checks
remain enforced. The worker expires abandoned active calls after 90 seconds, not 45;
clients use a shorter bounded recovery window. End/reject/revocation remain terminal.
`created_at`, `accepted_at`, `ended_at` are UTC epoch seconds. Devices render local
clock time, so USA/Russia clocks may legitimately differ. Accepted-call duration uses
the same server instants; this does not prove that RTP was audible throughout.
