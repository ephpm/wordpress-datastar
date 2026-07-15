#!/bin/sh
# One-shot WordPress installer for the wordpress-datastar demo.
# Runs in the wordpress:cli container (as root, named volume at /app).
set -eu

APP=/app

# wp-cli's own PHP defaults to 128M; core unpacking wants headroom.
wp() {
  command php -d memory_limit=512M /usr/local/bin/wp --path="$APP" --allow-root "$@"
}

cd "$APP"

if [ ! -f wp-load.php ]; then
  echo "[wpds] downloading WordPress core..."
  wp core download --locale=en_US
fi

cp /wp-config.php wp-config.php

# ePHPm v0.5.0 compat shim (see mu-plugins/ephpm-compat.php for the why).
mkdir -p wp-content/mu-plugins
cp /mu-plugins/ephpm-compat.php wp-content/mu-plugins/ephpm-compat.php

if ! wp core is-installed >/dev/null 2>&1; then
  echo "[wpds] installing WordPress..."
  wp core install \
    --url=http://localhost:9950 \
    --title='Datastar Live on ePHPm' \
    --admin_user=admin \
    --admin_password=admin123 \
    --admin_email=admin@example.test \
    --skip-email
fi

# Lightweight CLASSIC theme (PHP templates, ol.comment-list markup).
# Block themes are avoided on purpose — classic keeps the comment markup
# simple and matches ePHPm's known-good WordPress surface.
wp theme install twentytwentyone --activate || wp theme activate twentytwentyone

# The demo plugin is bind-mounted read-only into wp-content/plugins.
wp plugin activate datastar-live

# Demo-friendly discussion settings:
# - comment_previously_approved=0: first-time commenters are NOT held for
#   moderation (otherwise nothing pushes live until an admin approves).
# - comment_moderation=0: no manual gate (WP default, set explicitly).
wp option update comment_previously_approved 0
wp option update comment_moderation 0

# A post with comments open to demo against (idempotent via slug lookup).
if [ -z "$(wp post list --post_type=post --name=datastar-live-demo --field=ID)" ]; then
  wp post create \
    --post_title='Datastar Live Demo' \
    --post_name=datastar-live-demo \
    --post_status=publish \
    --post_content='Open this post in two browsers and comment — every open tab updates live, pushed over SSE by a worker-mode ePHPm sidecar. No Node, no Redis, no websocket service.' \
    --comment_status=open
fi

DEMO_ID=$(wp post list --post_type=post --name=datastar-live-demo --field=ID)
echo "[wpds] init complete — demo post ID: $DEMO_ID"
wp plugin list --fields=name,status
