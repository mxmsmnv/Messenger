# Native devices and sessions — 1.5.4 development candidate

No new tables, production deployment or provider change is required by this source
candidate. It uses existing mobile grants and hashed access/refresh tokens. The
operator still installs a pinned, verified release through the consuming site's
backup/release process; source tests do not establish an installed version.

Discovery advertises `device_sessions`. Native member API, Bearer only and without
cookies:

- `GET devices`: `{items:[{id,device_name,created_at,current}],has_more}`. The list
  contains only this member's unrevoked grants with at least one unused unexpired
  token. Current grant first, then creation date; at most100 items, plus an explicit
  overflow flag. `created_at` is the actual Unix sign-in time, not invented last
  activity. Connection IDs are identifiers, never access/refresh credentials.
- `POST revoke-device` with `{connection_id}`: idempotent user-scoped revocation;
  `{revoked:true,current_revoked:boolean}`. A foreign/nonexistent ID cannot reveal
  or modify another account. Revoked access and refresh tokens stop authenticating.
- `POST revoke-other-devices` with `{}`: revoke every other unrevoked grant of the
  member, including ones beyond the displayed100. The exemption derives only from
  the authenticated Bearer grant; request fields cannot choose another exemption.
  Returns `{revoked:count,current_revoked:false}`. Future fresh sign-ins are not
  prohibited by this operation.

Existing browser `/messenger-mobile/v1/devices/` remains browser-only and CSRF
protected. Its name/ID fields and single-device action remain compatible. The new
native routes never fall back to a cookie session. No passwords, token plaintext,
IP addresses or precise device location are returned by the listing.

Message-push dispatch already checks grant revocation. Call workers exclude
revoked grants and call-state expiry ends calls whose caller/answer grant was
revoked. An alert already submitted to APNs/FCM cannot be recalled. Native clients
must stop local audio, discard session/history state and require sign-in after
observing revocation; an offline device learns of a remote change on its next
request. This is not a remote physical wipe of previously downloaded plaintext.

Verification:17 identity/ownership/expiry/bulk checks, native REST metadata checks,
and64 disposable HTTP transport checks, including real POST grant revocation and
body-exemption spoof rejection. The full regression is recorded separately. The
HTTP fixture uses synthetic TLS indicators and SQLite services; it is not a live
website or real-TLS/device acceptance result.
