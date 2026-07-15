<?php
/**
 * WordPress config for the wordpress-datastar demo.
 *
 * Database: inside the ePHPm container, mysqli connects to 127.0.0.1:3306
 * (ePHPm's pooling MySQL proxy → mysql:3306). The wp-cli init container has
 * no proxy, so it overrides the host via WORDPRESS_DB_HOST=mysql.
 */

define( 'DB_NAME',     'wordpress' );
define( 'DB_USER',     'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST',     getenv( 'WORDPRESS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET',  'utf8mb4' );
define( 'DB_COLLATE',  '' );

// -- datastar-live plugin wiring (see plugins/datastar-live/) --
// Where the plugin publishes realtime events: the SSE container's RESP
// listener (ePHPm's embedded KV store speaking the Redis protocol).
define( 'DATASTAR_LIVE_KV_HOST', getenv( 'DATASTAR_LIVE_KV_HOST' ) ?: 'sse' );
define( 'DATASTAR_LIVE_KV_PORT', (int) ( getenv( 'DATASTAR_LIVE_KV_PORT' ) ?: 6379 ) );
// Base URL browsers use to open the SSE stream (host-visible address).
// Can also be changed at runtime under Settings → Datastar Live.
define( 'DATASTAR_LIVE_URL', getenv( 'DATASTAR_LIVE_URL' ) ?: 'http://localhost:9951' );

// -- Demo keys: replace before any real use --
// https://api.wordpress.org/secret-key/1.1/salt/
define( 'AUTH_KEY',         'wpds-demo-auth-key' );
define( 'SECURE_AUTH_KEY',  'wpds-demo-secure-auth-key' );
define( 'LOGGED_IN_KEY',    'wpds-demo-logged-in-key' );
define( 'NONCE_KEY',        'wpds-demo-nonce-key' );
define( 'AUTH_SALT',        'wpds-demo-auth-salt' );
define( 'SECURE_AUTH_SALT', 'wpds-demo-secure-auth-salt' );
define( 'LOGGED_IN_SALT',   'wpds-demo-logged-in-salt' );
define( 'NONCE_SALT',       'wpds-demo-nonce-salt' );

define( 'WP_HOME',    'http://localhost:9950' );
define( 'WP_SITEURL', 'http://localhost:9950' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', false );
define( 'DISABLE_WP_CRON', false );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'FS_METHOD', 'direct' );

$table_prefix = 'wp_';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
