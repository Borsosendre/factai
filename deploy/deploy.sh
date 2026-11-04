#!/usr/bin/env bash
# FACT.AI website: build and copy to the web directory.
# Usage:  ./deploy/deploy.sh /var/www/factai [web-user]
# Needs: python3, rsync, php (CLI). Run it as a user that may write the target and use sudo for the web user.
# It NEVER overwrites live data: content/news.json, assets/img/news/, admin/config.php and the data dir are kept.
set -euo pipefail
TARGET="${1:?Usage: deploy.sh <target-dir> [web-user]}"
WEBUSER="${2:-www-data}"
cd "$(dirname "$0")/.."

python3 build.py
mkdir -p "$TARGET"
rsync -a --delete \
  --exclude 'content/news.json' --exclude 'assets/img/news/' \
  --exclude 'admin/config.php' --exclude '_data/' \
  site/ "$TARGET"/

# first install only: seed the news file
[ -f "$TARGET/content/news.json" ] || cp site/content/news.json "$TARGET/content/news.json"
mkdir -p "$TARGET/assets/img/news"

# the PHP user must be able to write these (the dashboard saves here)
chown -R "$WEBUSER": "$TARGET/content" "$TARGET/assets/img/news" "$TARGET/en" "$TARGET/hu" "$TARGET/ro" 2>/dev/null \
  || sudo chown -R "$WEBUSER": "$TARGET/content" "$TARGET/assets/img/news" "$TARGET/en" "$TARGET/hu" "$TARGET/ro"

# re-apply the live news to the freshly copied pages
( cd "$TARGET" && sudo -u "$WEBUSER" php admin/rerender.php 2>/dev/null || php admin/rerender.php )
# news committed to the repository (content/news-feed.json): merged without overwriting dashboard edits
( cd "$TARGET" && sudo -u "$WEBUSER" php admin/import-feed.php "$OLDPWD" 2>/dev/null || php admin/import-feed.php "$OLDPWD" )
echo "Kész: $TARGET"
