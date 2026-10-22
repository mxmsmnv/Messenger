# Experimental audio calls — operator deployment gates

Messenger call endpoints/outbox are an **unreleased candidate**, additive to1.4.7;
production web messaging assets and database remain unchanged. On October21 the
owner authorized `ubuntu@atlas.narzan.com`: a separate authenticated coturn relay
was installed and tested, with private credentials outside Git. Provider delivery
and real audio calls are not yet verified; calls remain disabled in Messenger.

## Atlas infrastructure checkpoint — October21,2026

Dedicated `narzan-turn.service` and certificate-copy `narzan-turn-tls.timer` active;
default coturn unit masked. Existing narzan.com trusted certificate, private copies
under `/etc/narzan-calls`, HMAC secret generated on server without display. Only
3478TCP/UDP,5349TCP,60000–60127UDP added to UFW. Previous UFW state saved at
`/var/backups/narzan-calls-20261021/firewall-before.txt`. Public UDP test rejected
anonymous allocation, accepted temporary HMAC credentials and released allocation;
public TLS5349 verified hostname/chain. No live message schema or PHP config edits.
Use `refresh-turn-tls.py` only on this verified Atlas certificate-path layout.
Rollback: stop/disable the two dedicated units, remove **only** these exact
Narzan-commented UFW additions and preserve existing firewall/certificate files.
Never remove a shared relay/package without checking other owners.


## Installation order

1. Obtain a reviewed, committed Messenger candidate and an exact consuming-site
   deployment manifest. Back up database, module files and the existing private
   site configuration outside document root. Verify archive checksums and restore
   procedure before installing. Do not replace live code from an uncommitted tree.
2. Confirm the server, PHP-pool account/binary, public IPv4/NAT topology, free
   3478TCP/UDP,5349TCP and60000–60127UDP, existing firewall ownership and TLS full
   chain/key paths. `narzan@narzan.com` currently has no general sudo; a privileged
   administrator connection is required. Do not probe clpctlWrapper for escalation.
3. Install official distro `coturn` under a dedicated `narzan-turn.service`.
   Prevent package post-install from starting an unauthenticated default service:
   if coturn is absent and no existing coturn service is owned by another app,
   temporarily mask its default unit before package installation; keep the default
   unit disabled after installation. Never stop/overwrite an unrelated relay.
4. Generate one strong random TURN secret privately (minimum32 random bytes).
   Restrict `/etc/narzan-calls` and TLS/private configuration to root and the relay;
   expose only the shared secret to the site's exact PHP user through a narrow ACL.
   Do not print secrets, place them in CLI arguments, Git, logs or world-readable files.
   Populate the template. Add the VPS's own public IP to the deny-peer list, in
   addition to loopback/private/link-local/multicast restrictions. Do not offer
   unauthenticated TURN. Use an automatically renewed trusted TLS chain/key.
5. Add only the four required listener/relay firewall ranges, preserving every
   existing rule. Do not enable, disable or flush an existing firewall globally.
   Check cloud-provider network ACLs independently. Stage a dedicated systemd unit
   with the supplied sandbox settings; verify its runtime/config paths and user.
6. Prepare Firebase Android app `org.smnv.narzan.dev` without Analytics/paid-plan
   activation. Store `google-services.json` at the ignored Android app path. Use
   a server service account limited to FCM delivery; keep its JSON outside webroot.
   Keep automatic FCM registration disabled until the owner enables Calls in-app.
7. Enable Push Notifications for the existing Apple app identifier, create an APNs
   signing key through the owner's developer account, and store its .p8 privately
   with Key ID and Team ID. VoIP topic is `org.smnv.narzan.dev.voip`. Generate a
   matching development/distribution provisioning profile; test debug sandbox and
   TestFlight production registrations separately. Never substitute Apple-ID passwords.
8. Load private `messenger-config.example.php` from the site's private config as
   `$config->messengerCalls`. Keep both booleans false. As an existing approved
   administrator, call `$modules->get('Messenger')->installExperimentalCalls($actor)`
   once. This creates five calls-only tables (including quality feedback), with no base-message migration.
9. Validate provider authentication, one controlled push on each physical platform,
   authenticated TURN allocation/TLS and real two-device audio before setting
   `enabled` and `push_ready` true. Install the reviewed push service/timer under
   the actual PHP user, observing the site's existing runtime-path requirements.
   `processExperimentalCallPushes` is CLI+administrator-only, bounded20/three
   attempts, skips revoked/disabled/expired calls and retains encrypted signaling
   for at most roughly24h after call expiry. Device registrations expire30days.
10. Run accepted/pending/blocked/restricted/revoked/session-loss cases, ringing
    timeout, second-device acceptance, cold/closed incoming, lock screen, declined
    notification/microphone, muted/speaker/Bluetooth, music interruption/resumption,
    Wi-Fi/mobile transition and offline history/cache-clear checks. Confirm no
    existing web send/edit/delete/request regression. No synthetic push or mock
    engine establishes provider delivery, microphone acoustics or background E2E.

## Release boundaries

- Initial implementation is experimental **one-to-one audio**, no group/video,
  call recording or cross-site federation. The client defaults Calls off and
  explains possible connection/delivery failures.
- APNs/FCM invalidates/delivers call invitations. Ordinary message updates currently
  use a cheap authenticated revision poll, **not** a completed webhook/background
  message-push transport. Website/API latency is not ICMP ping; caching improves
  presentation time but does not reduce the underlying network RTT.
- Incoming calls cannot be guaranteed after user force-stop, before first unlock,
  denied permissions, provider/network failure or OS restrictions. Android full
  screen permission and Play call-app eligibility need independent review.
- Media uses WebRTC DTLS-SRTP, direct ICE paths where possible, authenticated TURN
  fallback otherwise. Compare the displayed fingerprint code through a trusted
  channel; this does not add message/file E2EE or certify endpoint identity.
- Update public privacy policy and Apple/Google store declarations for private
  on-device history/media, device push tokens, provider delivery and call metadata
  before publishing a build. Store approvals and production entitlement are gates.

## Rollback

First disable `enabled/push_ready`, stop/disable only Narzan's timer and relay unit,
revoke calls device registrations and restore reviewed module/configuration files.
Remove only the firewall rules introduced for this relay. Preserve existing
messages, module encryption keys, permissions, site assets and active web traffic.
Do not restore the whole database over intervening user messages. The additive
calls tables can remain disabled; destructive data removal is a separate decision.
Never uninstall another site's coturn service or rotate a shared secret blindly.

## Optional call-quality reports (1.5.0 candidate)

Back up the existing database and runtime first. Installing the code alone does
not create a feedback table or enable calls. Explicitly invoke the public
`installExperimentalCalls($administrator)` from the site's authorized CLI context
once to add `messenger_call_feedback`; the operation is repeatable and does not
alter the four existing calls tables. Validate `call-config.feedback_enabled`
using a real native grant, including while `enabled` is false.

Reports require user consent, stay on the connected website, and contain only
stars and enumerated issues plus participant/call/submission metadata. The
supported administrative export is `experimentalCallFeedback`, with separate
admin/export permissions, cursor pagination and a maximum page of 200. Never
publish its output. See API.md for payload, ownership and retention boundaries.

Keep an explicitly authorized maintenance invocation running if physical removal
at 30 days is required on an otherwise idle site. The existing CLI push worker
purges expired reports before checking whether calling is enabled, but this
release does not install or enable a timer. A disabled timer means cleanup awaits
a later authorized invocation, export or new submission. Read/export never
returns expired reports; backup retention is independent.

Rollback can restore the previous runtime without dropping the additive feedback
table; previous code ignores it. Do not delete reports or restore a database over
newer website messages as an ordinary code rollback. Web assets and live calling
configuration are unchanged by this candidate.
