<?php

/**
 * wordpress-datastar SSE service — the realtime layer for a WordPress that
 * cannot stream (it runs in ePHPm fpm mode, where the SAPI buffers all
 * output and flush() is a no-op).
 *
 * Runs on ePHPm WORKER MODE: this script boots once per worker thread and
 * loops over requests via the ePHPm worker primitives. SSE is delivered
 * with \Ephpm\Worker\send_response_stream(), which sends headers
 * immediately and pumps body chunks as they are produced — the only ePHPm
 * mode where a long-lived stream works.
 *
 * Routes:
 *   GET     /live/stream?post=<id>   SSE: new approved comments on post <id>
 *   GET     /live/stream?admin=1     SSE: live counter signals only
 *   GET     /healthz                 liveness probe
 *   GET     /                        info page (backend state, seq, online)
 *   OPTIONS *                        CORS preflight (Datastar sends a
 *                                    `datastar-request` header cross-origin)
 *
 * Event bus (written by the WordPress plugin over RESP2 into THIS
 * instance's KV store — [kv.redis_compat] in ephpm-sse.toml; read here
 * in-process via the native ephpm_kv_* functions, same store, same keys):
 *
 *   wpds:seq        monotonically increasing event sequence (the version
 *                   key every SSE loop watches)
 *   wpds:evt:<n>    JSON event payload, 5 min TTL:
 *                   {type: "comment"|"counts", post?: int, html?: string,
 *                    counts: {pending, approved, orders?}, t: unix}
 *   wpds:counts     JSON counter snapshot for connect-time state
 *   wpds:online     live SSE client count (maintained here)
 *
 * Wakeups are push-driven when the binary provides ephpm_kv_wait()
 * (ePHPm > v0.5.0), else a 100 ms version poll — feature-detected via
 * function_exists(), same pattern as the Pixelboard demo.
 *
 * CONSTRAINT (by design, see ephpm docs): one SSE connection parks one
 * worker thread for its whole lifetime, so [php] worker_count in
 * ephpm-sse.toml is the max concurrent live viewers.
 */

declare(strict_types=1);

const POLL_US     = 100_000; // fallback version-poll interval: 100 ms
const KEEPALIVE_S = 15.0;    // must stay well under [server.timeouts] idle (60 s)

const CORS_HEADERS = [
    // The WP pages live on another origin (localhost:9950 vs :9951).
    'Access-Control-Allow-Origin'  => '*',
    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
    'Access-Control-Allow-Headers' => 'Content-Type, datastar-request',
    'Access-Control-Max-Age'       => '86400',
];

// ── KV helpers ───────────────────────────────────────────────────────

function kv_available(): bool
{
    return \function_exists('ephpm_kv_get');
}

function kv_int(string $key): int
{
    $v = \ephpm_kv_get($key);
    return $v === null ? 0 : (int) $v;
}

/**
 * Fetch one event payload. The WP publisher INCRs wpds:seq before SETting
 * wpds:evt:<seq>, so a fresh seq can be visible a moment before its
 * payload — retry a null read once after 10 ms, then skip.
 */
function kv_event(int $seq): ?array
{
    $raw = \ephpm_kv_get('wpds:evt:' . $seq);
    if ($raw === null) {
        \usleep(10_000);
        $raw = \ephpm_kv_get('wpds:evt:' . $seq);
    }
    if ($raw === null) {
        return null;
    }
    $evt = \json_decode((string) $raw, true);
    return \is_array($evt) ? $evt : null;
}

// ── SSE wire format (Datastar events) ────────────────────────────────

/** One SSE event. $dataLines are the raw `data:` payloads (no newlines!). */
function sse_event(string $event, array $dataLines): string
{
    $out = "event: {$event}\n";
    foreach ($dataLines as $line) {
        $out .= "data: {$line}\n";
    }
    return $out . "\n";
}

function sse_signals(array $signals): string
{
    return sse_event('datastar-patch-signals', ['signals ' . \json_encode($signals)]);
}

/** Append one comment <li> into the live list on the page. */
function sse_append_comment(string $html): string
{
    return sse_event('datastar-patch-elements', [
        'selector #wpds-new-comments',
        'mode append',
        'elements ' . $html,
    ]);
}

/**
 * Render the SSE text for a batch of bus events, filtered per stream.
 *
 * @param array $events decoded bus events, oldest first
 * @param int   $post   post id filter (0 = none)
 * @param bool  $admin  admin stream (signals only)
 */
function render_events(array $events, int $post, bool $admin): string
{
    $out    = '';
    $counts = null;
    foreach ($events as $evt) {
        if (!$admin
            && ($evt['type'] ?? '') === 'comment'
            && (int) ($evt['post'] ?? 0) === $post
            && \is_string($evt['html'] ?? null)
        ) {
            $out .= sse_append_comment($evt['html']);
        }
        if (\is_array($evt['counts'] ?? null)) {
            $counts = $evt['counts']; // last one wins
        }
    }
    if ($counts !== null) {
        $out .= sse_signals($counts);
    }
    return $out;
}

// ── SSE stream (userland stream wrapper) ─────────────────────────────
// \Ephpm\Worker\send_response_stream() pumps any readable PHP stream to
// the client chunk by chunk. This wrapper turns that pull-based pump into
// a push-style SSE generator: stream_read() BLOCKS until it has an event
// (or a keepalive) and never returns '' — an empty read would end the pump.

final class SseStream
{
    /** @var resource|null set by PHP for wrapper instances */
    public $context;

    private int $post   = 0;
    private bool $admin = false;

    private int $lastSeq  = 0;
    private int $watchVer = 0;      // ephpm_kv_wait protocol: 0 = register
    private float $lastWrite = 0.0;
    private string $buf = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $opts        = \stream_context_get_options($this->context)['wpds'] ?? [];
        $this->post  = (int) ($opts['post'] ?? 0);
        $this->admin = (bool) ($opts['admin'] ?? false);

        // Only events newer than the connection are pushed; the page (or
        // the widget's PHP render) already contains history.
        $this->lastSeq   = kv_int('wpds:seq');
        $this->lastWrite = \microtime(true);

        // Immediate hello: proves the stream is live before any event, and
        // syncs counter signals from the bus snapshot.
        $this->buf = ": connected post={$this->post} admin=" . (int) $this->admin
                   . " seq={$this->lastSeq}\n\n";
        $counts = \ephpm_kv_get('wpds:counts');
        if ($counts !== null && \is_array($decoded = \json_decode((string) $counts, true))) {
            $this->buf .= sse_signals($decoded);
        }
        return true;
    }

    public function stream_read(int $count): string
    {
        // Drain buffered bytes from a previous short read first.
        if ($this->buf !== '') {
            $out = \substr($this->buf, 0, $count);
            $this->buf = (string) \substr($this->buf, $count);
            return $out;
        }

        // Client disconnects are detected by ePHPm on WRITE (response_chunk
        // fails and the pump stops), so the keepalive below also bounds how
        // long a dead client can park this worker (<= KEEPALIVE_S).
        return \function_exists('ephpm_kv_wait')
            ? $this->readWait($count)
            : $this->readPoll($count);
    }

    /**
     * Push path (ePHPm with ephpm_kv_wait, > v0.5.0): block on wpds:seq
     * until it is written or the keepalive budget runs out. Zero CPU while
     * idle; wakeup latency sub-ms instead of the poll interval.
     */
    private function readWait(int $count): string
    {
        $budgetMs = (int) \max(1.0, (KEEPALIVE_S - (\microtime(true) - $this->lastWrite)) * 1000.0);
        $r = \ephpm_kv_wait('wpds:seq', $this->watchVer, $budgetMs);
        if ($r === false) {                     // timeout → keepalive tick
            $this->lastWrite = \microtime(true);
            return ": keepalive\n\n";
        }
        $this->watchVer = (int) $r['version'];
        return $this->emitNewEvents($count);
    }

    /** Poll fallback (ePHPm <= v0.5.0): 100 ms version poll. */
    private function readPoll(int $count): string
    {
        while (true) {
            if (kv_int('wpds:seq') !== $this->lastSeq) {
                $chunk = $this->emitNewEvents($count);
                if ($chunk !== '') {
                    return $chunk;
                }
                // All events were filtered out for this stream — keep waiting.
            }
            if (\microtime(true) - $this->lastWrite >= KEEPALIVE_S) {
                $this->lastWrite = \microtime(true);
                return ": keepalive\n\n";
            }
            \usleep(POLL_US);
        }
    }

    /** Read events (lastSeq, current], render the filtered SSE text. */
    private function emitNewEvents(int $count): string
    {
        $cur = kv_int('wpds:seq');
        if ($cur <= $this->lastSeq) {
            return '';
        }
        $events = [];
        for ($i = $this->lastSeq + 1; $i <= $cur; $i++) {
            $evt = kv_event($i);
            if ($evt !== null) {
                $events[] = $evt;
            }
        }
        $this->lastSeq = $cur;

        $text = render_events($events, $this->post, $this->admin);
        if ($text === '') {
            return '';
        }
        $this->buf = $text;
        $this->lastWrite = \microtime(true);
        $out = \substr($this->buf, 0, $count);
        $this->buf = (string) \substr($this->buf, $count);
        return $out;
    }

    public function stream_eof(): bool
    {
        return false; // ends when the client goes away, not by EOF
    }

    public function stream_close(): void {}

    /** @return array|false */
    public function stream_stat()
    {
        return false;
    }
}

\stream_wrapper_register('wpdssse', SseStream::class);

// ── Route handlers ───────────────────────────────────────────────────

function handle_stream(array $query): void
{
    if (!kv_available()) {
        \Ephpm\Worker\send_response(
            503,
            ['Content-Type' => 'text/plain'] + CORS_HEADERS,
            "KV store unavailable\n"
        );
        return;
    }

    $ctx = \stream_context_create(['wpds' => [
        'post'  => isset($query['post']) ? (int) $query['post'] : 0,
        'admin' => !empty($query['admin']),
    ]]);
    $stream = \fopen('wpdssse://events', 'rb', false, $ctx);

    \ephpm_kv_incr('wpds:online');

    \Ephpm\Worker\send_response_stream(
        200,
        [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-store',
            'X-Accel-Buffering' => 'no',
        ] + CORS_HEADERS,
        $stream
    );

    // send_response_stream returns when ePHPm aborts the pump: the client
    // disconnected or stalled past [server.timeouts] idle.
    \fclose($stream);
    \ephpm_kv_decr('wpds:online');
}

function handle_info(): void
{
    $body = "wordpress-datastar SSE service (ePHPm worker mode)\n"
        . 'kv:        ' . (kv_available() ? 'native ephpm_kv_*' : 'UNAVAILABLE') . "\n"
        . 'kv_wait:   ' . (\function_exists('ephpm_kv_wait') ? 'yes (push wakeups)' : 'no (100 ms poll fallback)') . "\n"
        . 'seq:       ' . kv_int('wpds:seq') . "\n"
        . 'online:    ' . kv_int('wpds:online') . "\n"
        . "stream:    GET /live/stream?post=<id> | ?admin=1\n";
    \Ephpm\Worker\send_response(200, ['Content-Type' => 'text/plain'] + CORS_HEADERS, $body);
}

// ── Boot-once + request loop ─────────────────────────────────────────

\error_log('[wpds-sse] worker booted (kv_wait: '
    . (\function_exists('ephpm_kv_wait') ? 'yes' : 'no') . ')');

while (($envelope = \Ephpm\Worker\take_request()) !== null) {
    $server = $envelope->serverVars();
    $method = \strtoupper($server['REQUEST_METHOD'] ?? 'GET');
    $uri    = $server['REQUEST_URI'] ?? '/';
    $path   = \strtok($uri, '?') ?: '/';

    match (true) {
        $method === 'OPTIONS'
            => \Ephpm\Worker\send_response(204, CORS_HEADERS, ''),
        $path === '/live/stream' && $method === 'GET'
            => handle_stream($envelope->query()),
        $path === '/healthz'
            => \Ephpm\Worker\send_response(200, ['Content-Type' => 'text/plain'] + CORS_HEADERS, "ok\n"),
        $path === '/' && $method === 'GET'
            => handle_info(),
        default
            => \Ephpm\Worker\send_response(404, ['Content-Type' => 'text/plain'] + CORS_HEADERS, "not found\n"),
    };
}

\error_log('[wpds-sse] worker loop ended');
