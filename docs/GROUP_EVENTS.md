# Group events — 1.6.8 development candidate

This source is not installed on production. The site operator must separately
approve a backup, rollback and `installGroupEvents($administrator)` migration. It
adds `messenger_group_events`, `messenger_group_event_answers` and
`messenger_group_event_locks`. Discovery, member requests and ordinary upgrades do
not migrate. Existing group/site/member authorization remains authoritative.

## Event and response contract

An event is an ordinary authorized group message plus structured encrypted data.
Title1–160characters, description0–1000, location0–240, an integer UTC `starts_at`,
a PHP-recognized IANA timezone and duration5–10080minutes. New/changed times must be
future and at most180days away. Timezone is retained for local display; the UTC
instant removes recipient-device ambiguity. Invalid dates/DST gaps are rejected by
native/web entry; the chosen instant is shown before submission. No recurring event,
external calendar account or calendar permission is introduced.

| Public method | REST route / body |
| --- | --- |
| `createGroupEvent($actor,$id,$details,$clientId)` | POST `group-event-create`: `id,details,client_id` |
| `groupEvent($actor,$messageId)` | GET `group-event?message_id=…` |
| `updateGroupEvent($actor,$messageId,$version,$details)` | POST `group-event-update`: `message_id,version,details` |
| `respondGroupEvent($actor,$messageId,$answer,$reminder,$version)` | POST `group-event-respond`: `message_id,version,answer,reminder_minutes` |
| `cancelGroupEvent($actor,$messageId,$version)` | POST `group-event-cancel`: `message_id,version` |

`answer` is `yes`, `maybe`, `no` or explicit null/withdrawal. Counts include current
members with access from their join floor, without publishing respondent identities.
The actor sees only their own answer and reminder. Names/text are never SQL authority.
All browser writes need CSRF. Integers and booleans are not interchangeable.

Creation requires a16–64character immutable operation ID and binds all original
details through the existing message fingerprint. A retry returns current event
state or a hidden receipt; it cannot revive hidden/deleted content. Forwarding is
plain text and creates no new calendar entry. Only the original author or a current
owner/admin may change/cancel; current send/block rights apply. Ordinary Edit is
refused. Changes increment version and clear prior responses/reminder consent.
A response must name the version actually shown; an unseen reschedule is a409.
Matching change/cancel retries do not mutate again. Cancellation never reopens.
The old-client fallback explicitly says Event cancelled; original retry fingerprints
remain unchanged. Deletion/expiry clears details, answers and reminder rows while
retaining only the operation tombstone. Titles/locations/descriptions/answers are
authenticated-encrypted at rest, server-readable, not E2EE. Scheduling timestamps
and queue state remain operational metadata.

Actor rate locks precede group/event locks. Effective changes are capped60/minute;
matching desired-state retries are free. Current locking visibility reads protect
against outer repeatable-read snapshots. Real MySQL tests exercise a waiting RSVP
across cancellation and a reschedule; neither can commit a stale response.

## Reminders and operator setup

`group_events` / browser `events_supported` covers the calendar. A separate
`group_event_reminders` / `event_reminders_supported` capability requires configured
standard message push. There is no implied provider setup or scheduled service.
New reminder consent is refused while this integration is unconfigured.

A current member explicitly selects5,15,60 or1440minutes before the event after a
Yes/Maybe response; default is no reminder. A reminder cannot be set in the past or
after the message's earlier retention deadline. Reading the event message does not
cancel this separately requested reminder. Mute, blocks, loss of rights, hide,
delete, expiry, cancellation, event edits, withdrawal and revoked/disabled devices
are rechecked. Withdrawal keeps a revision tombstone so an in-flight old worker
cannot mark fresh consent as delivered. Event edits require a new RSVP/consent.

After separately approved installation, run the CLI
`php bin/group-event-reminders.php /absolute/processwire/root` through the site's
trusted serialized worker mechanism. It uses the existing private `messengerPush`
provider configuration and configured worker administrator. No new service is
installed by this source change. `processGroupEventReminders($operator,$sender,5)`
accepts a synthetic sender for tests; the real adapter is
`MessengerCallPush::sendEventReminder(platform,token,messageId,chatId,account,sound,revision)`.

A bounded batch claims jobs, retries at most5times with backoff, and discards work
more than5minutes late. Per-job targets are bounded to10current devices. Each
provider call rechecks current consent/version, group access and registration.
No SQL lock is held across provider I/O: already delivered alerts cannot be recalled.
Delivery is at-least-once, not an exactly-once promise; stable revision/collapse IDs
limit duplicate alerts. FCM TTL/APNs expiry is300seconds. Payloads contain only
routing IDs/account/revision and a generic Event reminder, never event text or
attendee lists. Android deduplication uses a separate reminder key; iOS foreground
presentation checks the bound account. These are standard alerts, never VoIP pushes.
Browser controls configure consent but do not register browser-push subscriptions.
Actual remote delivery/closed-app E2E remains a separate deployment/device gate.

## Evidence

`tests/group-events.php`, `group-event-reminders.php`, `web-group-events.cjs`,
`message-push-provider.php`, HTTP transport and guarded `group-events-integration.php`
cover schema/rights, immutable retries, versions, privacy, retention and workers.
Provider tests use synthetic transport only. Companion native six-case tests cover
actual create/edit/cancel/RSVP/reminder controls, dark UI and ambiguous retry cleanup.
