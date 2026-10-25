# Group polls — 1.6.5 development candidate

Single-choice ballots in enabled groups. An administrator must explicitly call
`installGroupPolls($administrator)` in the consuming site's reviewed migration.
No member request, discovery or ordinary upgrade creates these three tables.
Pin `src/MessengerPolls.php` and `assets/messenger-polls.js` with the other runtime
files; see [API](../API.md#group-polls-165-development-candidate).

Any current member who can send may create a question with2–8 distinct options.
Members who can see that original message may cast, change or retract one vote.
Only its author or a current group owner/administrator may permanently close it.
Exact desired-state retries do not change results or consume the member60/minute
change allowance. Poll creation retains its original message operation ID/body;
a changed payload conflicts. Old clients see the question and numbered options
as text. Poll messages cannot be edited into inconsistent ballots; forwarding
produces ordinary text, not another live poll.

Options and individual choices use the existing authenticated server encryption,
with message/member-specific context. Members receive counts and their own choice,
never a voter list. Departed votes remain in anonymous totals. Later members have
no access to older polls, and departure/hiding/deletion/moderation/expiry enforce
the existing message boundary. This is anonymous **to other members**, not to the
website operator, and is not E2EE. Vote audits contain no chosen option. Votes and
close events advance the group revision so foreground clients refresh results.
Global deletion or expiry erases structured options/choices as well as message
content; only an immutable operation tombstone remains for redacted retries.

The canonical lock order is conversation, then group/membership/poll rows. Current
locking reads after waiting prevent a voter from accepting stale open-state data.
Actual MySQL concurrency testing first exposed a poll-before-conversation lock,
then a JOIN-plan lock inversion. Both were corrected; the final separate waiting
voter is rejected after the administrator commits close, with no late vote.

Verification:58 poll-domain checks,20 poll DOM checks,19 host web behavior checks
(including425/429 text retry retention),146 HTTP checks and the full module suite.
Actual ProcessWire3.0.255/MySQL8.4.9 clone:32 group checks,12 inherited forwarding
checks and20 poll checks, including concurrent close and content erasure. No push
provider executed. Owned clone database dropped and MySQL stopped.

Web controls are capability-gated and use the consuming site's framework adapter.
Native Narzan integration and its specific evidence are tracked in its repository.
No production installation, completed physical-device acceptance or store release
is implied by these source checks. Live setup, backup/restore and pinned consuming
runtime deployment remain separate operator gates. API writes retain ordinary
browser CSRF and native bearer authority; no actor/role comes from request JSON.
