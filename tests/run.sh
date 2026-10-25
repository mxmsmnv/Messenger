#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
while IFS= read -r file; do php -l "$file" >/dev/null; done < <(find . -path './.git' -prune -o -path './vendor' -prune -o -path './.work' -prune -o -name node_modules -prune -o -name '*.php' -type f -print)
node --check assets/messenger.js
node --check assets/messenger-admin.js
node --check assets/messenger-groups.js
node --check assets/messenger-media.js
node --check assets/messenger-tools.js
node tests/web-dates.cjs
if [[ -d tests/web-dom/node_modules/jsdom ]]; then node tests/web-behavior.cjs; node tests/web-groups.cjs; node tests/web-media.cjs; node tests/web-forwarding.cjs; node tests/web-reactions.cjs; node tests/web-pins.cjs; node tests/web-history-search.cjs; else echo "Web DOM behavior: skipped (run npm ci --prefix tests/web-dom --ignore-scripts)"; fi
php tests/contracts.php
php tests/upgrade-patch.php
php tests/wirewall-integration.php
php tests/mobile-domain.php
php tests/client-policy.php
php tests/groups.php
php tests/forwarding.php
php tests/reactions.php
php tests/pins.php
php tests/history-search.php
php tests/calls.php
php tests/call-feedback.php
php tests/message-push.php
php tests/message-push-provider.php
php tests/image-albums.php
php tests/message-info.php
php tests/member-features.php
php tests/conversation-settings.php
php tests/mobile-auth.php
php tests/device-sessions.php
php tests/web-mobile-transport.php
python3 tests/http-transport.py
