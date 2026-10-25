# Group mentions — 1.6.7 development candidate

Not installed on production. After a separately approved backup/migration, an
administrator may call `installGroupMentions($administrator)`. The additive tables
are `messenger_mentions`, `messenger_mention_preferences` and
`messenger_mention_locks`. Discovery and ordinary requests/upgrades never create them.
A missing legacy schema hides capability; an incomplete installed schema fails closed.

Discovery advertises `group_mentions`; the browser session advertises
`mentions_supported`. Current group membership and all normal message/site rights
remain required. The web composer offers current member usernames after `@`.
Email addresses, substrings, quote lines and canonical attachment filenames do not
mention anyone. At most20 distinct current recipients may be captured in one send.
Editing recaptures entities; deletion/expiry erases them. Forwarding does not create
new mentions from the copied text. Names are authenticated-encrypted at rest,
not end-to-end encrypted; the site operator retains its established authority.

## Contract

| Method | API route | Result |
| --- | --- | --- |
| `setGroupMentionMode($actor,$id,$mentionsOnly)` | POST `group-mention-mode`, JSON `id,mentions_only` | exact group ID and confirmed boolean |
| `groupMentions($actor,$id,$before=null)` | GET `group-mentions?id=…&before=…` | own actor/group, up to30 messages, exclusive `next_before` |

`mentions_only` requires a JSON boolean, never integers or arbitrary strings.
Browser writes require CSRF. A posted recipient cannot select another user's feed.
The feed applies current membership, join/history/read visibility, hides and expiry;
its cursor never grants older history. It contains read mentions too. Public messages
include `mentions:[{id,name}]` and the current actor's `mentioned` boolean.

Group chat details include the actor's `mentions_only`. When enabled, chat summaries
also include `last_mention`: the latest unread visible non-silent incoming mention,
independent from `last_message`. Read/hidden/expired/removed state clears this preview.
This avoids losing or repeating foreground notifications behind later ordinary posts.

The preference limits notification eligibility only. It does not unmute a group,
change unread counts, grant membership, or hide ordinary group messages. Queueing
and delivery recheck current preference/mention visibility; push payloads carry no
message text/usernames. Existing group email enqueue support remains limited: the
core email enqueue path is direct-chat only. The shared email worker guard also
protects group records created by an integration; this does not add group email.

Preference changes take the actor rate lock before the group lock. Matching retries
are free; effective changes are bounded to60/minute. MySQL uses current locking reads
for membership/preferences. Mobile push joins compare opaque grant IDs as bytes to
avoid mixed default-collation failures without changing existing schema.

## Verification

See `tests/mentions.php`, `tests/mentions-push.php`, `tests/web-mentions.cjs`, HTTP
transport checks and the guarded `tests/mentions-integration.php` clone test. Provider
calls are synthetic. Ordinary web behavior and old sites remain capability-gated.
Production deployment and its operator migration require a separate release decision.
