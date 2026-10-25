#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
while IFS= read -r file; do php -l "$file" >/dev/null; done < <(find . -path './.git' -prune -o -path './vendor' -prune -o -path './.work' -prune -o -name node_modules -prune -o -name '*.php' -type f -print)
node --check assets/messenger.js
node --check assets/messenger-admin.js
node --check assets/messenger-groups.js
node --check assets/messenger-polls.js
node --check assets/messenger-group-links.js
node --check assets/messenger-mentions.js
node --check assets/messenger-events.js
node --check assets/messenger-media.js
node --check assets/messenger-tools.js
node --check assets/messenger-activity.js
node tests/web-dates.cjs
if [[ -d tests/web-dom/node_modules/jsdom ]]; then node tests/web-behavior.cjs; node tests/web-groups.cjs; node tests/web-polls.cjs; node tests/web-group-links.cjs; node tests/web-mentions.cjs; node tests/web-group-events.cjs; node tests/web-media.cjs; node tests/web-forwarding.cjs; node tests/web-reactions.cjs; node tests/web-pins.cjs; node tests/web-history-search.cjs; node tests/web-saved-messages.cjs; node tests/web-media-catalog.cjs; node tests/web-activity.cjs; node tests/web-activity-integration.cjs; else echo "Web DOM behavior: skipped (run npm ci --prefix tests/web-dom --ignore-scripts)"; fi
php tests/contracts.php
php tests/upgrade-patch.php
php tests/wirewall-integration.php
php tests/mobile-domain.php
php tests/client-policy.php
php tests/groups.php
php tests/polls.php
php tests/group-links.php
php tests/group-events.php
php tests/group-event-reminders.php
php tests/account-event-reminders.php
php tests/mentions.php
php tests/mentions-push.php
php tests/forwarding.php
php tests/reactions.php
php tests/pins.php
php tests/history-search.php
php tests/media-catalog.php
php tests/activity.php
php tests/saved-messages.php
php tests/calls.php
php tests/account-calls.php
php tests/call-events.php
php tests/write-acks.php
php tests/silent-messages.php
php tests/call-feedback.php
php tests/message-push.php
php tests/account-message-push.php
php tests/message-push-provider.php
php tests/image-albums.php
php tests/message-info.php
php tests/member-features.php
php tests/conversation-settings.php
php tests/mobile-auth.php
php tests/device-sessions.php
php tests/web-mobile-transport.php
python3 tests/http-transport.py
