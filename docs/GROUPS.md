# Group conversations — 1.5.3 development candidate

This is source under test, not proof of a consuming-site installation or a
three-device production E2E result. Groups are off by default. Direct conversations,
their pair hashes and existing user/permission/encryption configuration are retained.

## Operator activation and rollback

1. Back up the consuming database and pinned module/runtime/configuration before
   any installation. Verify a restore in an isolated database first.
2. Install a pinned, tested source revision through the site's established release
   process. Patch upgrades143–153 perform no schema operations automatically.
3. An explicitly authorized module administrator calls `installGroups($actor)`;
   this creates only `messenger_groups`, `messenger_group_members` and
   `messenger_group_invites`. It never changes profile templates or direct pair IDs.
4. Explicitly enable `groups_enabled`. Discovery advertises `groups` only when
   the toggle and all three tables are present. Group creation alone never enables
   calls, push, email, workers or another site's policy.
5. Roll back by disabling `groups_enabled`, stopping any task-owned test worker,
   restoring the previously pinned runtime if required, and retaining group tables
   and encrypted data for a separately authorized recovery/deletion decision.
   Old clients never receive groups in their default inbox/search.

No production install/migration or operator-setting mutation was performed in
this development run. The site's existing encryption key remains controlling;
HTTPS and authenticated encryption at rest are not end-to-end encryption.

## Membership and privacy contract

- A group supports at most100 current members and outstanding unexpired invitations
  combined. Creation follows the site's new-conversation rate; every group invitation also
  consumes the same new-recipient allowance as direct first contact. Invitations honor the first-contact
  `messagingDecision` hook. An explicit invitation is required even for open sites;
  nobody is silently forced into a group.
- Invitations expire after7days. Only their intended authenticated user can accept
  or decline. Inviter rights, suspension, site policy and pairwise blocks are
  rechecked before acceptance. Temporary ProcessWire actor context is always
  restored, including policy exceptions.
- New and returning members see messages sent strictly after their join floor.
  The immutable group membership floor is separate from hide/archive/read state.
  It applies to messages, attachment IDs, search and read metadata. No imported
  older history or fabricated system messages are inserted.
- Owner/admin/member roles are server-authoritative. Admins may rename/update the
  photo, invite and remove regular members. Only the owner changes admin roles,
  removes admins, transfers ownership or deletes the group for everyone. The
  owner must transfer ownership or delete the group before leaving.
- Removing/leaving deletes active participation and invalidates subsequent API,
  file and pending-push eligibility. An already submitted generic provider alert
  cannot be recalled; it never contains message content, and opening it still
  requires current authorized access. Previously downloaded plaintext cannot be
  remotely recalled. Narzan does not put group message history into its durable
  direct-chat cache in this candidate; membership must be revalidated online.
- Group deletion makes the conversation inaccessible to ordinary members. This
  is an application deletion state, not a promise of physical database erasure;
  encrypted records/audit are retained under the site's existing operator policy.
- Names and photo bytes use separate authenticated-encryption envelopes bound to
  the group ID. No public profile field or public image URL is created. Photos are
  JPEG/PNG/WebP only, at most512KiB and1024×1024; clients crop before submission.
- Sender names are supplied on visible messages. Read receipts aggregate the
  current eligible audience, excluding sender/newcomers for older messages. No
  fabricated aggregate read timestamp is returned.
- Existing personal archive, mute, clearing and server expiry controls continue.
  Group retention changes require an owner/admin. Existing own-message edit/delete
  windows apply; site moderation authority is not granted merely by group ownership.
- Group calls, invitation links, mentions, polls, scheduled events and AI participants
  are not implied by the group core. Direct audio calls explicitly reject groups.

## Public methods and REST

Member methods require the current authenticated ProcessWire User as actor:
`createGroup(actor,title,userIds,clientId)`, `groupDetails(id,actor)`,
`groupInvitations(actor)`, `respondGroupInvitation(id,actor,accept)`,
`changeGroup(id,actor,action,body)` and `groupAvatar(id,actor)`.

`groupRequest` is the bundled transport dispatcher, not a template integration API.
All REST resources use the existing `/messenger-api/v1/` transport: browser session
+CSRF or cookie-free native bearer; existing rates and no-store headers apply.

| Resource | Method | Fields |
| --- | --- | --- |
| `group-create` | POST | `title`, integer `user_ids`, immutable `client_id` |
| `group-details` | GET | `id` |
| `group-invitations` | GET | none |
| `group-respond` | POST | `id`, boolean `accept` |
| `group-rename` | POST | `id`, `title` |
| `group-invite` | POST | `id`, integer `user_ids` |
| `group-remove` | POST | `id`, integer `user_id` |
| `group-role` | POST | `id`, `user_id`, `role=admin/member` (owner only) |
| `group-transfer` | POST | `id`, `user_id` (owner only) |
| `group-leave`, `group-delete` | POST | `id` |
| `group-avatar` | GET | `id` |
| `group-avatar` | POST | `id`, validated `avatar` object or explicit null |

Group detail/mutation acknowledgements add `type=group`, `role`, `member_count`,
`group_version`, `has_avatar` and member details. `other_user.id=0` is a compatibility
presentation slot for the group title, never a synthetic person to call/block.
Direct-only clients retain existing responses: `inbox` and `search` include groups
only with `include_groups=1` and server capability. Existing `conversation`,
`messages`, send/attachment/read/hide/archive/mute and expiry routes enforce actual
membership. A group creation retry uses the original creator/client ID plus a
keyed request fingerprint; ownership transfer does not change this identity.

## Verification boundary

`tests/groups.php` exercises actual module methods against an isolated SQLite
fixture, including three users, denied roles, rejoin floors, private attachment
access, encrypted photo, search, rollback and create retries. `tests/web-groups.cjs`
exercises real group JavaScript/DOM interactions with a synthetic transport.
`tests/message-push.php` checks group removal between enqueue and dispatch.
Full module regression remains mandatory. MySQL/real ProcessWire, browser login,
real APNs/FCM, native phones and a production backup/restore are separate gates.
The web group composer includes bounded Gallery/Files/Camera selection and an
explicit microphone recording/stop/preview/send flow, with immutable attachment
retry IDs and private raster/audio/video/file viewing. It uses the existing
authorized attachment endpoint and never inserts public file URLs. DOM checks
cover cancellation, changed-account rejection, immutable retry/ACK binding and
pixel bounds; native browser codecs, camera/microphone and full three-account
web/mobile E2E remain acceptance gates. Do not label the full group backlog
finished. New web clients bind requests to the originally loaded session actor
through an optional consistency header; it never supplies authorization.

## Verified development checkpoint — October24,2026

The full regression passes:68 group permission/lifecycle checks,13 browser group
checks,57 browser media checks,29 message-push checks and the legacy direct-chat
suite. `tests/groups-integration.php` also passes32 checks using actual
ProcessWire3.0.255 and MySQL8.4.9 in a marked, cloned disposable database. That
covers independent actors, join/rejoin floors, private attachments/photos,
aggregate receipts, owner transfer and continued direct messaging. The owned
clone was removed and its private MySQL stopped; the original fixture/site and
production were preserved.

Web group list/header photos use private authenticated requests and a bounded
16-entry object-URL cache, released on page exit. `avatar_version` changes only
when the photo changes; renames and member edits do not trigger photo downloads.
Narzan separately bounds this cache to16 entries/4MiB and two simultaneous
requests, outside its navigation mutex. These are presentation caches, not
permanent group history or guarantees of remote plaintext recall.

The real-database test is deliberately not part of automatic `tests/run.sh`:
it requires an explicitly supplied `--root`, the exact disposable marker and
an isolated `narzan_groups_<8hex>` database. Do not point it at a live website.
Native fixture runs are separate component evidence; browser camera/audio codecs,
web album layouts and real multi-account phone delivery remain acceptance gates.


## Optional polls — 1.6.5 candidate

Explicit separate operator schema and current message/membership permissions apply.
See [polls](POLLS.md) for single-choice/anonymous aggregate semantics, web controls,
content erasure, MySQL concurrency evidence and the deployment boundary.
