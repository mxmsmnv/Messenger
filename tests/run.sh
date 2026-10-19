#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
while IFS= read -r file; do php -l "$file" >/dev/null; done < <(find . -path './.git' -prune -o -path './vendor' -prune -o -name '*.php' -type f -print)
node --check assets/messenger.js
node --check assets/messenger-admin.js
php tests/contracts.php
php tests/upgrade-patch.php
php tests/mobile-domain.php
php tests/image-albums.php
php tests/message-info.php
php tests/member-features.php
php tests/conversation-settings.php
php tests/mobile-auth.php
php tests/web-mobile-transport.php
python3 tests/http-transport.py
