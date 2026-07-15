# wordpress-datastar — live WordPress on ePHPm

**Realtime comments and a live admin dashboard on stock WordPress — no Node,
no Redis, no websocket service.** Two copies of one static binary
([ePHPm](https://github.com/ephpm/ephpm)) do everything: serve WordPress,
stream [Datastar](https://data-star.dev) SSE, and bus events between the two
through ePHPm's embedded KV store.

Post a comment in one browser; it appears on every other open copy of the
page — and the admin dashboard's moderation counters tick — without a reload.

```
 browser ── page loads ─────────────► wp   ePHPm #1 (fpm mode)
    │                                 │    WordPress 7.0 + datastar-live plugin
    │                                 │    └─ mysqli → 127.0.0.1:3306 (ePHPm's
    │                                 │       pooling MySQL proxy) → mysql:8.4
    │                                 │
    │                                 │ raw RESP2 (Redis protocol, fsockopen)
    │                                 ▼
    └── data-init="@get(…)" ───────► sse  ePHPm #2 (worker mode)
        one SSE stream per tab            └─ embedded KV store = the event bus
                                             (wpds:seq / wpds:evt:<n>)
```

Frontend: the Datastar CDN bundle (`datastar@v1.0.2`) + `data-*` attributes
the plugin stamps onto the page. Backend events are plain
`datastar-patch-elements` / `datastar-patch-signals` SSE frames.

## Quickstart

```bash
podman compose up -d      # or: docker compose up -d
```

- WordPress: <http://localhost:9950> — demo post at `/?p=4`
  (admin: `admin` / `admin123`, dashboard has the live widget)
- SSE service: <http://localhost:9951> (`/live/stream?post=4`, `/healthz`)

Open the demo post in two browser windows, comment in one, watch both.

## Why two containers (the vhost question)

One ePHPm instance with two vhosts (WP in fpm, SSE in worker mode) would be
nicer — but ePHPm v0.5.0 has a **single global `[php] mode`**, and
`mode = "worker"` together with `sites_dir` (vhosting) is a hard config
validation error (`ephpm-config/src/lib.rs` — "per-host worker pools are a
later phase"). fpm mode cannot stream SSE at all: the SAPI buffers all
output and `flush()` is a no-op, so an SSE loop arrives as one batch when
the script exits.

So the demo ships the hybrid the
[feasibility doc](https://github.com/ephpm/datastar-demo/blob/main/docs/wordpress-feasibility.md)
recommends: **WordPress in fpm mode, a worker-mode ePHPm sidecar for the
realtime layer, the sidecar's KV store (exposed via `[kv.redis_compat]`,
the Redis-protocol listener) as the bus between them.**

## The two stages

**Stage 0 — request/response (works with fpm alone).** The plugin's comment
box `@post`s to a REST route that answers with a *finite* SSE-formatted body
(fragment echo + cleared-form signals) and exits. Buffered delivery is fine
for that. The admin widget's "Refresh now" button is the same pattern.

**Stage 1 — realtime push (the sidecar).** Plugin hooks (`comment_post`,
`transition_comment_status`) publish events onto the KV bus:
`INCR wpds:seq`, `SET wpds:evt:<seq> {json}`. Every open page holds one SSE
stream to the sidecar (`data-init="@get('http://localhost:9951/live/stream?post=4')"`);
the worker watches `wpds:seq` (100 ms poll on v0.5.0; `ephpm_kv_wait()` is
feature-detected and used when the binary has it) and relays fragments to
every subscribed viewer. Admin streams (`?admin=1`) get counter signals only.

The plugin's KV transport is feature-detected: native `ephpm_kv_*`
(single-instance, opt-in via `DATASTAR_LIVE_KV_HOST=native`), Predis if
present, else a built-in ~60-line raw RESP2 client over `fsockopen` — the
path this demo exercises. The SSE base URL is configurable under
**Settings → Datastar Live** (or `DATASTAR_LIVE_URL`).

## The honest constraint: `worker_count` = max live viewers

One SSE connection parks one ePHPm worker thread for its whole lifetime
(workers handle exactly one request at a time). `worker_count = 16` in
`ephpm-sse.toml` is therefore the hard cap on concurrent open tabs. Dead
clients are reaped within one keepalive interval (15 s). Scaling beyond
worker-count needs the server-side SSE hub proposed as PR-3 in the
[ePHPm integration spec](https://github.com/ephpm/datastar-demo/blob/main/docs/ephpm-integration-spec.md)
(headed for the [ePHPm roadmap](https://ephpm.dev/roadmap/)): Rust owns N
client connections, one worker renders a fragment once, the bytes broadcast
to all N.

## Validated transcript (podman, clean `compose down -v` → `up -d`)

**1. WordPress installs, classic theme renders, plugin activates**

```console
$ podman logs wordpress-datastar-wp-init-1 | tail -3
[wpds] init complete — demo post ID: 4
datastar-live	active            (+ ephpm-compat	must-use)

$ curl -s -o /dev/null -w "%{http_code} %{size_download} bytes\n" http://localhost:9950/?p=4
200 33078 bytes

$ curl -s http://localhost:9951/
wordpress-datastar SSE service (ePHPm worker mode)
kv:        native ephpm_kv_*
kv_wait:   no (100 ms poll fallback)
seq:       0
online:    0
```

**2. Stage 0 — comment via the Datastar endpoint, fragment returned, 2xx**

```console
$ curl -s -X POST "http://localhost:9950/index.php?rest_route=/datastar-live/v1/comment&post=4" \
    -H "Content-Type: application/json" \
    -d '{"author":"Ada","email":"ada@example.test","content":"Hello from Stage 0"}'
event: datastar-patch-elements
data: selector #wpds-echo
data: mode inner
data: elements <li id="wpds-echo-2" class="comment wpds-live-comment">…<b class="fn">Ada</b>…<p>Hello from Stage 0</p>…</li>

event: datastar-patch-signals
data: signals {"content":"","status":"Comment posted — watch it arrive on every open tab."}

(HTTP 200, text/event-stream;charset=UTF-8)
```

**3 + 4. Stage 1 — two concurrent SSE clients, event arrives mid-stream**

Two `curl -N` clients opened first, then a comment POSTed from a third shell:

```console
$ curl -N "http://localhost:9951/live/stream?post=4"       # clients A and B,
: connected post=4 admin=0 seq=1                            # identical output
event: datastar-patch-signals
data: signals {"pending":0,"approved":2}

event: datastar-patch-elements                              # ← arrives mid-stream,
data: selector #wpds-new-comments                           #   ~100 ms after the POST
data: mode append
data: elements <li id="wpds-comment-3" class="comment wpds-live-comment">…<b class="fn">Grace</b>…<p>Pushed to every open tab mid-stream</p>…</li>

event: datastar-patch-signals
data: signals {"pending":0,"approved":3}
```

**Moderation lifecycle** (`comment_moderation=1`): the POST answers
`"Comment held for moderation."`; the admin stream ticks
`pending 0 → 1`; `wp comment approve 4` pushes the fragment to post viewers
and `pending → 0`:

```console
$ curl -N "http://localhost:9951/live/stream?admin=1"
: connected post=0 admin=1 seq=2
event: datastar-patch-signals
data: signals {"pending":0,"approved":3}

event: datastar-patch-signals
data: signals {"pending":1,"approved":3}      # ← comment held

event: datastar-patch-signals
data: signals {"pending":0,"approved":4}      # ← wp comment approve
```

Also verified: `: keepalive` after 15 s idle; `wpds:online` presence up/down;
CORS preflight (`OPTIONS` → 204 with `access-control-allow-headers:
Content-Type, datastar-request`); dashboard REST route 401 for anonymous.

## Findings, caveats, deferred

- **ePHPm v0.5.0 fpm bug found (workaround shipped):** output produced
  during PHP request shutdown is not captured — the response is snapshotted
  at script end. Minimal repro: `<?php ob_start(); echo "hello";` → HTTP 200,
  `content-length: 0` (PHP normally flushes that buffer at shutdown; output
  echoed from `register_shutdown_function` is likewise dropped).
  WordPress 7.0's new template-enhancement output buffer
  (`wp_start_template_enhancement_output_buffer`, finalized on the
  `shutdown` action) trips exactly this, so **every theme page rendered
  0 bytes** on stock WP 7.0 + ePHPm v0.5.0.
  `mu-plugins/ephpm-compat.php` opts out of that buffer (streaming template
  output is an explicitly supported WordPress mode). Remove the shim once
  ePHPm captures shutdown-phase output. WP ≤ 6.x needs no shim.
- **Don't auto-detect the native KV functions.** In the two-container
  topology the WP container is *also* an ePHPm, so `ephpm_kv_*` exist —
  but they write to its own local store, which nobody streams from. The
  plugin only uses them when `DATASTAR_LIVE_KV_HOST=native` is explicit.
- **INCR→SET race on the bus:** a stream can glimpse `wpds:seq` before the
  event payload lands; the worker retries a null read once after 10 ms.
  ePHPm's KV has no transactions/pipelines to make this atomic.
- **The submitter sees their comment twice on purpose:** once in the "echo"
  confirmation box (Stage 0 response, `wpds-echo-*` ids) and once in the
  live list (Stage 1 push, `wpds-comment-*` ids). Distinct ids, no morph
  conflicts, and Stage 0 keeps working with the sidecar down.
- **Demo-only settings:** comment flood-throttling and duplicate detection
  are disabled by the plugin; the RESP listener has no password; CORS is
  `*`; secrets in `wp-config.php` are placeholders. Harden all four before
  any real deployment.
- **Fragments use plugin markup, not theme markup.** Pushed `<li>`s go to
  the plugin's own live list, not into the theme's `ol.comment-list`
  (theme-agnostic; a reload shows the canonical theme rendering).
- **Deferred:** WooCommerce live order counters (the code paths exist
  behind `wc_orders_count()` feature-detection but were not validated —
  Woo wasn't installed); browser-level E2E (validation above is at the
  SSE/HTTP wire level, where Datastar's behavior is deterministic).

## Upgrades when ePHPm v0.5.1 ships

- **`ephpm_kv_wait()`** — the SSE worker already feature-detects it
  (`sse/worker.php`): push latency drops from ≤100 ms to sub-ms and idle
  CPU to zero, no code changes needed here.
- **Streaming brotli** (`[server.compression] streaming = "sse"`) — one
  encoder window across the whole SSE stream turns repeated fragment
  pushes into tiny wire deltas; enable it in `ephpm-sse.toml` when the
  knob exists.

## Repo layout

| Path | What |
|---|---|
| `compose.yaml` | mysql:8.4 + wp-init (wp-cli) + wp (ePHPm fpm) + sse (ePHPm worker) |
| `ephpm-wp.toml` / `ephpm-sse.toml` | the two ePHPm configs (annotated) |
| `plugins/datastar-live/` | the WordPress plugin (Stage 0 + Stage 1 write side) |
| `mu-plugins/ephpm-compat.php` | WP 7.0 × ePHPm v0.5.0 output-buffer shim |
| `sse/worker.php` | the SSE service (worker mode, KV-watching stream) |
| `scripts/install-wp.sh` | one-shot WP install (core, theme, plugin, demo post) |
| `wp-config.php` | WP config (ePHPm MySQL proxy + KV bus wiring) |
