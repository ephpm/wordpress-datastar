<?php
/**
 * Plugin Name: Datastar Live
 * Description: Realtime comments and admin counters for WordPress on ePHPm — Datastar on the frontend, ePHPm's KV store as the event bus, a worker-mode ePHPm sidecar streaming SSE. No Node, no Redis, no websocket service.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Author: ePHPm project
 * License: MIT
 *
 * Two stages, both implemented here:
 *
 *  Stage 0 — request/response (works in plain fpm mode, no sidecar needed):
 *    the comment box posts via Datastar's @post to a REST route that answers
 *    with an SSE-FORMATTED BODY (datastar-patch-* events) and exits. fpm mode
 *    buffers the whole response — fine, it's a finite response, not a stream.
 *
 *  Stage 1 — realtime push (needs the worker-mode SSE sidecar):
 *    comment hooks publish events into ePHPm's KV store (see WPDS_KV for the
 *    transport feature-detection); every open page holds one SSE stream to
 *    the sidecar, which relays events as datastar-patch-* to all viewers.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-wpds-kv.php';

const WPDS_DATASTAR_CDN = 'https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.2/bundles/datastar.js';

// ── Config helpers ──────────────────────────────────────────────────────

/** Base URL of the SSE sidecar as seen FROM THE BROWSER (no trailing /). */
function wpds_live_url(): string {
	$opt = trim( (string) get_option( 'wpds_live_url', '' ) );
	if ( '' !== $opt ) {
		return untrailingslashit( $opt );
	}
	if ( defined( 'DATASTAR_LIVE_URL' ) && '' !== DATASTAR_LIVE_URL ) {
		return untrailingslashit( DATASTAR_LIVE_URL );
	}
	return 'http://localhost:9951';
}

// ── Event bus (Stage 1 write side) ──────────────────────────────────────

/** Comment counters (+ WooCommerce processing orders when present). */
function wpds_counts(): array {
	$c      = wp_count_comments();
	$counts = array(
		'pending'  => (int) $c->moderated,
		'approved' => (int) $c->approved,
	);
	if ( function_exists( 'wc_orders_count' ) ) {
		$counts['orders'] = (int) wc_orders_count( 'processing' );
	}
	return $counts;
}

/**
 * Publish one event onto the KV bus.
 *
 * Protocol (read by sse/worker.php):
 *   INCR wpds:seq                → the version key SSE workers watch
 *   SET  wpds:evt:<seq> <json>   → the event payload (5 min TTL)
 *   SET  wpds:counts <json>      → connect-time snapshot for new streams
 *
 * INCR-then-SET means a poller can glimpse the new seq before the payload
 * lands; the worker retries a null read once after 10 ms (documented there).
 *
 * @return bool true if the event reached the bus (Stage 1 is live).
 */
function wpds_publish( array $evt ): bool {
	$kv = WPDS_KV::instance();

	$evt['counts'] = wpds_counts();
	$evt['t']      = time();

	$seq = $kv->incr( 'wpds:seq' );
	if ( null === $seq ) {
		return false;
	}
	$ok = $kv->set( 'wpds:evt:' . $seq, (string) wp_json_encode( $evt ), 300 );
	$kv->set( 'wpds:counts', (string) wp_json_encode( $evt['counts'] ) );
	return $ok;
}

// New comment inserted (fires for the REST route below AND for wp-admin,
// wp-comments-post.php, XML-RPC — every path).
add_action( 'comment_post', function ( $comment_id, $approved ) {
	$comment = get_comment( $comment_id );
	if ( ! $comment ) {
		return;
	}
	if ( 1 === (int) $approved ) {
		wpds_publish( array(
			'type' => 'comment',
			'post' => (int) $comment->comment_post_ID,
			'html' => wpds_render_comment_li( $comment ),
		) );
	} else {
		// Pending/spam: viewers get nothing, admin counters update.
		wpds_publish( array( 'type' => 'counts' ) );
	}
}, 10, 2 );

// Approve / unapprove / spam / trash from wp-admin.
add_action( 'transition_comment_status', function ( $new_status, $old_status, $comment ) {
	if ( 'approved' === $new_status ) {
		wpds_publish( array(
			'type' => 'comment',
			'post' => (int) $comment->comment_post_ID,
			'html' => wpds_render_comment_li( $comment ),
		) );
	} else {
		wpds_publish( array( 'type' => 'counts' ) );
	}
}, 10, 3 );

// Demo affordances: posting several comments quickly from one IP is the
// whole point of a realtime demo — disable WP's flood interval and the
// duplicate-comment rejection. Remove these two lines for production use.
add_filter( 'comment_flood_filter', '__return_false' );
add_filter( 'duplicate_comment_id', '__return_zero' );

// ── Fragment rendering ──────────────────────────────────────────────────

/**
 * Render one comment as a single-line <li> fragment (SSE `data:` lines must
 * not contain raw newlines). Markup mirrors classic-theme comment lists.
 *
 * @param WP_Comment $comment   The comment.
 * @param string     $id_prefix 'wpds-comment' for stream pushes,
 *                              'wpds-echo' for the submitter's Stage 0 echo
 *                              (distinct ids — the same comment may appear
 *                              in both places on the submitter's page).
 */
function wpds_render_comment_li( $comment, string $id_prefix = 'wpds-comment' ): string {
	$author  = esc_html( $comment->comment_author ?: 'Anonymous' );
	$when    = esc_html( get_comment_date( 'M j, Y H:i', $comment ) );
	$content = esc_html( $comment->comment_content );
	$content = trim( preg_replace( '/\s+/', ' ', $content ) );
	$id      = $id_prefix . '-' . (int) $comment->comment_ID;
	$pending = '0' === $comment->comment_approved
		? ' <em class="wpds-pending-tag">(awaiting moderation)</em>' : '';

	return '<li id="' . esc_attr( $id ) . '" class="comment wpds-live-comment">'
		. '<article class="comment-body">'
		. '<footer class="comment-meta"><b class="fn">' . $author . '</b>'
		. ' <span class="wpds-when">' . $when . '</span>' . $pending . '</footer>'
		. '<div class="comment-content"><p>' . $content . '</p></div>'
		. '</article></li>';
}

// ── SSE-formatted responses (Stage 0) ───────────────────────────────────

/** One SSE event block. */
function wpds_sse_event( string $event, array $data_lines ): string {
	$out = "event: {$event}\n";
	foreach ( $data_lines as $line ) {
		$out .= "data: {$line}\n";
	}
	return $out . "\n";
}

/**
 * Emit a complete SSE-formatted response body and exit.
 *
 * In ePHPm fpm mode the whole body is buffered and delivered when the
 * script exits — which is exactly what we want for a finite patch response
 * (this is NOT a long-lived stream; those live in the SSE sidecar).
 */
function wpds_sse_exit( string $body ): void {
	status_header( 200 );
	header( 'Content-Type: text/event-stream' );
	header( 'Cache-Control: no-store' );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- SSE wire format, fragments escaped at render time.
	exit;
}

// ── REST routes ─────────────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
	// POST /wp-json/datastar-live/v1/comment?post=<id>
	// Body: Datastar signals JSON {author, email, content, ...}.
	register_rest_route( 'datastar-live/v1', '/comment', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // same trust model as wp-comments-post.php
		'callback'            => 'wpds_rest_comment',
	) );

	// GET /wp-json/datastar-live/v1/dashboard — fresh counters as
	// datastar-patch-signals (used by the admin widget's refresh).
	register_rest_route( 'datastar-live/v1', '/dashboard', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'moderate_comments' );
		},
		'callback'            => function () {
			wpds_sse_exit( wpds_sse_event(
				'datastar-patch-signals',
				array( 'signals ' . wp_json_encode( wpds_counts() + array( 'status' => 'refreshed ' . gmdate( 'H:i:s' ) ) ) )
			) );
		},
	) );
} );

/** @param WP_REST_Request $request */
function wpds_rest_comment( $request ) {
	$signals = (array) $request->get_json_params();
	$post_id = (int) $request->get_param( 'post' );

	$result = wp_handle_comment_submission( array(
		'comment_post_ID' => $post_id,
		'author'          => sanitize_text_field( (string) ( $signals['author'] ?? '' ) ),
		'email'           => sanitize_email( (string) ( $signals['email'] ?? '' ) ),
		'url'             => '',
		'comment'         => (string) ( $signals['content'] ?? '' ),
	) );

	if ( is_wp_error( $result ) ) {
		wpds_sse_exit( wpds_sse_event(
			'datastar-patch-signals',
			array( 'signals ' . wp_json_encode( array( 'status' => $result->get_error_message() ) ) )
		) );
	}

	$approved = '1' === $result->comment_approved || 1 === $result->comment_approved;
	$status   = $approved
		? 'Comment posted — watch it arrive on every open tab.'
		: 'Comment held for moderation.';

	// Stage 0 payload: the new-comment fragment (echoed into the
	// submitter's own confirmation box) + cleared form signals. The live
	// list on every open tab — including the submitter's — is fed by the
	// Stage 1 stream, so the echo uses distinct element ids.
	$body = wpds_sse_event( 'datastar-patch-elements', array(
		'selector #wpds-echo',
		'mode inner',
		'elements ' . wpds_render_comment_li( $result, 'wpds-echo' ),
	) );
	$body .= wpds_sse_event( 'datastar-patch-signals', array(
		'signals ' . wp_json_encode( array( 'content' => '', 'status' => $status ) ),
	) );
	wpds_sse_exit( $body );
}

// ── Frontend (Stage 0 form + Stage 1 subscription) ──────────────────────

add_action( 'wp_head', function () {
	if ( ! is_singular() || ! comments_open() ) {
		return;
	}
	echo '<script type="module" src="' . esc_url( WPDS_DATASTAR_CDN ) . '"></script>' . "\n";
	echo '<style>
		#wpds-live { border: 1px solid #ddd; border-radius: 8px; padding: 1rem 1.25rem; margin: 1.5rem 0; }
		#wpds-live h3 { margin-top: 0; }
		#wpds-live input, #wpds-live textarea { display: block; width: 100%; margin: .4rem 0; padding: .4rem; }
		#wpds-live .wpds-status { color: #2271b1; min-height: 1.2em; font-style: italic; }
		#wpds-new-comments, #wpds-echo-wrap ul { list-style: none; padding-left: 0; }
		.wpds-live-comment { border-left: 3px solid #2271b1; padding: .4rem .8rem; margin: .5rem 0; background: #f6f7f7; }
		.wpds-when { color: #757575; font-size: .85em; }
		.wpds-pending-tag { color: #996800; }
	</style>' . "\n";
} );

add_action( 'comment_form_before', function () {
	$post_id    = get_the_ID();
	$stream_url = wpds_live_url() . '/live/stream?post=' . $post_id;
	$post_url   = add_query_arg( 'post', $post_id, rest_url( 'datastar-live/v1/comment' ) );
	$signals    = wp_json_encode( array(
		'author'  => '',
		'email'   => '',
		'content' => '',
		'status'  => '',
	) );
	?>
	<div id="wpds-live"
		data-signals='<?php echo esc_attr( $signals ); ?>'
		data-init="@get('<?php echo esc_url( $stream_url ); ?>')">
		<h3>Live comments <small>— Datastar &times; ePHPm</small></h3>
		<p>New approved comments appear below on <em>every</em> open copy of this
		page, pushed over SSE by a worker-mode ePHPm sidecar.</p>
		<ol class="comment-list" id="wpds-new-comments"></ol>
		<div id="wpds-echo-wrap"><ul id="wpds-echo"></ul></div>
		<p class="wpds-status" data-text="$status"></p>
		<input type="text" placeholder="Name" data-bind:author>
		<input type="email" placeholder="Email" data-bind:email>
		<textarea rows="3" placeholder="Say something…" data-bind:content></textarea>
		<button class="wp-element-button"
			data-on:click="@post('<?php echo esc_url( $post_url ); ?>')">
			Post comment (live)
		</button>
	</div>
	<?php
} );

// ── Admin dashboard widget ──────────────────────────────────────────────

add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'moderate_comments' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'wpds_dashboard', 'Datastar Live — realtime counters', 'wpds_dashboard_widget' );
} );

// Datastar only on the dashboard screen.
add_action( 'admin_head-index.php', function () {
	echo '<script type="module" src="' . esc_url( WPDS_DATASTAR_CDN ) . '"></script>' . "\n";
} );

function wpds_dashboard_widget(): void {
	$counts  = wpds_counts();
	$signals = wp_json_encode( $counts + array( 'status' => '' ) );
	$stream  = wpds_live_url() . '/live/stream?admin=1';
	$refresh = rest_url( 'datastar-live/v1/dashboard' );
	?>
	<div data-signals='<?php echo esc_attr( $signals ); ?>'>
		<div data-init="@get('<?php echo esc_url( $stream ); ?>')">
			<p style="font-size:1.05em">
				Awaiting moderation: <b data-text="$pending" style="font-size:1.4em"><?php echo (int) $counts['pending']; ?></b>
				&nbsp;·&nbsp; Approved: <b data-text="$approved"><?php echo (int) $counts['approved']; ?></b>
				<?php if ( isset( $counts['orders'] ) ) : ?>
					&nbsp;·&nbsp; Orders (processing): <b data-text="$orders"><?php echo (int) $counts['orders']; ?></b>
				<?php endif; ?>
			</p>
			<p>
				<button class="button" data-on:click="@get('<?php echo esc_url( $refresh ); ?>')">Refresh now</button>
				<em data-text="$status"></em>
			</p>
			<p class="description">Counters update live over SSE (Stage 1); the
			button is the Stage 0 request/response fallback.</p>
		</div>
	</div>
	<?php
}

// ── Settings (Settings → Datastar Live) ─────────────────────────────────

add_action( 'admin_menu', function () {
	add_options_page( 'Datastar Live', 'Datastar Live', 'manage_options', 'datastar-live', 'wpds_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'wpds_settings', 'wpds_live_url', array(
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	) );
} );

function wpds_settings_page(): void {
	$kv = WPDS_KV::instance();
	?>
	<div class="wrap">
		<h1>Datastar Live</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpds_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpds_live_url">SSE service base URL</label></th>
					<td>
						<input name="wpds_live_url" id="wpds_live_url" type="url" class="regular-text"
							value="<?php echo esc_attr( get_option( 'wpds_live_url', '' ) ); ?>"
							placeholder="<?php echo esc_attr( defined( 'DATASTAR_LIVE_URL' ) ? DATASTAR_LIVE_URL : 'http://localhost:9951' ); ?>">
						<p class="description">As reachable from the <em>browser</em>.
						Empty = the <code>DATASTAR_LIVE_URL</code> constant.
						Currently: <code><?php echo esc_html( wpds_live_url() ); ?></code></p>
					</td>
				</tr>
				<tr>
					<th scope="row">KV bus</th>
					<td>
						backend: <code><?php echo esc_html( $kv->backend() ); ?></code> —
						<?php echo $kv->available()
							? '<span style="color:green">reachable</span>'
							: '<span style="color:red">UNREACHABLE (Stage 1 push disabled; Stage 0 still works)</span>'; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
