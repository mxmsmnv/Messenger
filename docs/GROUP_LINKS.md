# Group invitation links — 1.6.6 development candidate

Not installed on a production site. Existing sites require an explicitly approved
operator migration and their own release/rollback checks. No provider is called by
this feature. Group links do not grant access to people without an eligible account
on the same site. They are not federation or anonymous registration.

## Operator contract

Enable the existing group feature and call `installGroupLinks($administrator)` only
from a trusted operator workflow after a backup. This creates three additive tables:
`messenger_group_links`, `messenger_group_link_uses`, and
`messenger_group_link_locks`. Capability reads, member requests and ordinary upgrades
never create them. No new public route or callback setting is needed: the consuming
site must already serve Messenger at its valid HTTPS `public_path`.

Discovery exposes `group_links` and `messages_path`; browser session exposes
`group_links_supported` and `messages_path`. Readiness does not require mobile login
to be enabled for the web interface. Missing schema/config hides the controls.

## Permission and privacy boundaries

- Current owner/admin creates or revokes. New creation replaces all earlier grants
  for this group. Revocation includes the exact link ID, so a stale screen cannot
  revoke a replacement. An old matching creation retry never reactivates a grant.
- Creation requires a 16–64 character immutable operation ID, lifetime of
  3,600–2,592,000 seconds and 1–100 uses. Reusing an ID with changed limits conflicts.
- A server-generated 256-bit token is encrypted with authenticated purpose-bound
  encryption at rest. A separate hash locates it. No token is stored in audit data.
  Revocation wipes the encrypted token and retains the operation/hash tombstone.
- Share a link as `https://site/messages/#narzan-group=TOKEN`. The fragment is not an
  HTTP query. Preview/join send it only in a POST body to the authenticated same-site
  API; browser writes still require CSRF. The web UI strips the supported fragment
  after session initialization and waits for explicit preview, then explicit Join.
  The site must assess its own third-party page scripts; scripts on the same page
  can access fragments before initialization. Share grants privately.
- Preview contains title, member count and limited grant state, no history/member
  directory/issuer identity. Join applies current site policy, all group blocks,
  restrictions, outgoing invitation quota and capacity including pending invites.
- New membership starts at the current history floor. A removed/departed user cannot
  reuse the same consumed grant; a new invitation is required. Already-current
  members can reopen their existing group without consuming another use, including
  through a stale/exhausted grant; this grants no additional rights.
- Demotion, removal, departure, ownership transfer and group deletion permanently
  invalidate applicable grants. Promotion never revives revoked tokens.
- Quota and membership updates are atomic with sorted actor locks before the group
  lock. Rate limits cap effective link changes at 60/minute per actor. Expiry is
  checked at use, not just by a background job. Link operations never notify contacts
  or publish the credential into group history.

## Transport

All routes below require current member authorization. These operations require
HTTPS. JSON booleans cannot substitute for numeric limits or identities.

| Route | Method/body | Result |
| --- | --- | --- |
| `group-link-status` | GET `id` | `{link:null}` or current admin grant |
| `group-link-create` | POST `id, expires_in, max_uses, client_id` | grant and `accepted_client_id` |
| `group-link-revoke` | POST `id, link_id` | exact `id, conversation_id, revoked:true` |
| `group-link-preview` | POST `token` | bounded preview, no membership |
| `group-link-join` | POST `token` | current group details after explicit join |

Active admin grants include ID, group/issuer IDs, creation/expiry, use counts, token
and messages path. Inactive grants return `token:null`. This is a bearer invitation,
not end-to-end encryption. The operator can decrypt stored credentials.

## Verification and remaining gates

Domain and HTTP tests cover permissions, CSRF, immutable retries, stale revoke,
expiry, quotas, blocks, membership floors and malformed input. Web DOM tests exercise
real control handlers. The guarded ProcessWire/MySQL clone verifies encrypted rows,
joins, shared direct/group invitation budgets and writers waiting across committed
revocation or newly full capacity. No live schema/site changed.
Native acceptance is recorded in Narzan's companion `docs/GROUP_LINKS.md`.

Retain tombstones while immutable operation IDs remain replayable; do not casually
purge/reuse IDs. Plan table retention/index/storage capacity as part of site rollout.
Production backup/restore, upgrade, actual multi-client and physical-device testing
remain separate owner-authorized release gates. Do not mark the whole TODO complete.
