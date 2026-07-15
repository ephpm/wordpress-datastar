<?php
/**
 * Plugin Name: ePHPm v0.5.0 compatibility shim
 * Description: Disables WordPress 7.0's template-enhancement output buffer, which is finalized during PHP request shutdown — output produced that late is not captured by ePHPm v0.5.0's fpm SAPI (the response is snapshotted at script end), so every theme page would render 0 bytes. Streaming (unbuffered) template output is an explicitly supported WordPress mode and is what fpm ePHPm needs. Remove once ePHPm captures shutdown-phase output.
 *
 * Minimal reproduction of the underlying ePHPm issue (no WordPress needed):
 *
 *   <?php ob_start(); echo "hello";   // → HTTP 200, content-length: 0
 *
 * PHP's request shutdown normally flushes that buffer into the SAPI;
 * ePHPm v0.5.0 fpm mode captures the response before that happens. The
 * same applies to output echoed from register_shutdown_function callbacks.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', PHP_INT_MAX );
