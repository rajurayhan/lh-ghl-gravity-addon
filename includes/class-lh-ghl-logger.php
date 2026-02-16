<?php
/**
 * GoHighLevel Logger
 *
 * Structured logging wrapper using Gravity Forms logging methods.
 * Provides debug-gated and always-on logging for API interactions,
 * validation errors, and processing steps throughout the plugin.
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LH_GHL_Logger
 *
 * Uses the add-on's log_debug() and log_error() so messages appear in the
 * prefix, debug-mode gating, and structured formatters for API traffic,
 * validation failures, and general processing messages.
 */
class LH_GHL_Logger {

    /**
     * Log prefix used in every message for easy filtering.
     *
     * @var string
     */
    private const PREFIX = '[GoHighLevel]';

    /**
     * Keys that should be redacted when logging request/response bodies.
     *
     * @var string[]
     */
    private const SENSITIVE_KEYS = array(
        'authorization',
        'api_key',
        'apiKey',
        'password',
        'secret',
        'token',
    );

    /**
     * Maximum length (characters) for a serialized body before truncation.
     *
     * @var int
     */
    private const MAX_BODY_LENGTH = 2000;

    /**
     * Reference to the main add-on instance.
     *
     * @var GFAddOn
     */
    private GFAddOn $addon;

    /**
     * Constructor.
     *
     * @param GFAddOn $addon The add-on instance.
     */
    public function __construct( GFAddOn $addon ) {
        $this->addon = $addon;
    }

    // =========================================================================
    // Task 4.5 — Debug Mode Gating
    // =========================================================================

    /**
     * Check whether debug mode is enabled in plugin settings.
     *
     * Debug-level messages are only written when this returns true.
     * Error-level messages are always written regardless of this setting.
     *
     * @return bool
     */
    public function is_debug_enabled(): bool {
        return (bool) $this->addon->get_plugin_setting( 'lh_ghl_debug_mode' );
    }

    // =========================================================================
    // Task 4.1 — Core Logging Methods
    // =========================================================================

    /**
     * Log a debug-level message (only when debug mode is on).
     *
     * Use for informational / trace-level output that helps during development
     * or troubleshooting but should be silent in production.
     *
     * @param string $message The log message.
     *
     * @return void
     */
    public function debug( string $message ): void {
        if ( $this->is_debug_enabled() ) {
            $this->addon->log_debug( self::PREFIX . ' ' . $message );
        }
    }

    /**
     * Log an informational message (only when debug mode is on).
     *
     * Alias for debug() — use for general processing step messages
     * (e.g. "Starting feed processing for entry #123").
     *
     * @param string $message The log message.
     *
     * @return void
     */
    public function info( string $message ): void {
        $this->debug( $message );
    }

    /**
     * Log an error-level message (always, regardless of debug mode).
     *
     * Use for genuine failures: HTTP errors, API rejections, validation
     * failures that prevent processing, etc.
     *
     * @param string $message The log message.
     *
     * @return void
     */
    public function error( string $message ): void {
        $this->addon->log_error( self::PREFIX . ' ' . $message );
    }

    /**
     * Log a warning-level message (always, regardless of debug mode).
     *
     * Use for non-fatal issues that deserve attention — e.g. a missing
     * optional field or a recoverable API quirk.
     *
     * @param string $message The log message.
     *
     * @return void
     */
    public function warning( string $message ): void {
        $this->addon->log_error( self::PREFIX . ' [WARNING] ' . $message );
    }

    // =========================================================================
    // Task 4.2 — Log API Requests
    // =========================================================================

    /**
     * Log an outgoing API request.
     *
     * Captures the HTTP method, full URL, and a sanitized version of the
     * request body (sensitive keys are redacted, long bodies are truncated).
     * Only written when debug mode is enabled.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string $url    The full request URL.
     * @param array  $body   Request body payload (will be sanitized before logging).
     *
     * @return void
     */
    public function log_api_request( string $method, string $url, array $body = array() ): void {
        if ( ! $this->is_debug_enabled() ) {
            return;
        }

        $parts = array(
            'API Request:',
            strtoupper( $method ),
            $this->sanitize_url( $url ),
        );

        if ( ! empty( $body ) ) {
            $sanitized_body = $this->sanitize_body( $body );
            $encoded        = wp_json_encode( $sanitized_body, JSON_UNESCAPED_SLASHES );
            $parts[]        = '| Body: ' . $this->maybe_truncate( $encoded );
        }

        $this->debug( implode( ' ', $parts ) );
    }

    // =========================================================================
    // Task 4.3 — Log API Responses
    // =========================================================================

    /**
     * Log an API response.
     *
     * Captures the HTTP status code, response timing, and a sanitized
     * summary of the response body.  Written at debug level for successful
     * responses; error-level responses are also logged via parse_response()
     * in the API class.
     *
     * @param int   $status_code HTTP status code.
     * @param mixed $body        Decoded response body (array or null).
     * @param float $elapsed_ms  Time taken for the request in milliseconds.
     *
     * @return void
     */
    public function log_api_response( int $status_code, mixed $body = null, float $elapsed_ms = 0.0 ): void {
        if ( ! $this->is_debug_enabled() ) {
            return;
        }

        $parts = array(
            'API Response:',
            sprintf( 'HTTP %d', $status_code ),
            sprintf( '(%.2fms)', $elapsed_ms ),
        );

        if ( is_array( $body ) && ! empty( $body ) ) {
            $sanitized_body = $this->sanitize_body( $body );
            $encoded        = wp_json_encode( $sanitized_body, JSON_UNESCAPED_SLASHES );
            $parts[]        = '| Body: ' . $this->maybe_truncate( $encoded );
        }

        $this->debug( implode( ' ', $parts ) );
    }

    // =========================================================================
    // Task 4.4 — Log Validation Errors & Failures
    // =========================================================================

    /**
     * Log a validation error.
     *
     * Validation errors indicate that processing was halted because
     * required data was missing or malformed (e.g. missing email).
     * These are always logged at error level.
     *
     * @param string $field   The field or context that failed validation.
     * @param string $message A human-readable description of the failure.
     * @param array  $context Optional additional context (entry ID, feed ID, etc.).
     *
     * @return void
     */
    public function log_validation_error( string $field, string $message, array $context = array() ): void {
        $parts = array(
            'Validation Error:',
            sprintf( '[%s]', $field ),
            $message,
        );

        if ( ! empty( $context ) ) {
            $parts[] = '| Context: ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
        }

        $this->error( implode( ' ', $parts ) );
    }

    /**
     * Log a processing failure.
     *
     * Use when a step in the sync pipeline fails (e.g. contact creation
     * returned an error, opportunity creation was rejected).
     * Always logged at error level.
     *
     * @param string        $step     The processing step that failed (e.g. 'create_contact').
     * @param string        $message  A description of the failure.
     * @param WP_Error|null $wp_error Optional WP_Error to extract code/message from.
     *
     * @return void
     */
    public function log_failure( string $step, string $message, ?WP_Error $wp_error = null ): void {
        $parts = array(
            'Processing Failure:',
            sprintf( '[%s]', $step ),
            $message,
        );

        if ( $wp_error instanceof WP_Error ) {
            $parts[] = sprintf( '| Error Code: %s', $wp_error->get_error_code() );
            $parts[] = sprintf( '| Error Message: %s', $wp_error->get_error_message() );
        }

        $this->error( implode( ' ', $parts ) );
    }

    /**
     * Log the start of feed processing for an entry.
     *
     * Provides a clear marker in the logs for where processing of a
     * specific form entry begins.
     *
     * @param int    $entry_id  The Gravity Forms entry ID.
     * @param int    $form_id   The form ID.
     * @param string $feed_name The feed name.
     *
     * @return void
     */
    public function log_processing_start( int $entry_id, int $form_id, string $feed_name ): void {
        $this->info(
            sprintf(
                '--- Processing Start --- Entry #%d | Form #%d | Feed: %s',
                $entry_id,
                $form_id,
                $feed_name
            )
        );
    }

    /**
     * Log the completion of feed processing for an entry.
     *
     * @param int    $entry_id The Gravity Forms entry ID.
     * @param string $result   Result summary (e.g. 'success', 'skipped', 'failed').
     * @param float  $elapsed  Total processing time in seconds.
     *
     * @return void
     */
    public function log_processing_end( int $entry_id, string $result, float $elapsed = 0.0 ): void {
        $level_method = ( 'failed' === $result ) ? 'error' : 'info';

        $message = sprintf(
            '--- Processing End --- Entry #%d | Result: %s | Total: %.2fms',
            $entry_id,
            $result,
            $elapsed * 1000
        );

        $this->$level_method( $message );
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Sanitize a URL for logging — strip query-string values that may contain tokens.
     *
     * Preserves query parameter names but redacts values for sensitive keys.
     *
     * @param string $url The raw URL.
     *
     * @return string Sanitized URL safe for logging.
     */
    private function sanitize_url( string $url ): string {
        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['query'] ) ) {
            return $url;
        }

        parse_str( $parsed['query'], $params );
        $clean_params = $this->redact_sensitive( $params );

        // Rebuild URL with sanitized query string.
        $base  = ( $parsed['scheme'] ?? 'https' ) . '://';
        $base .= $parsed['host'] ?? '';
        $base .= $parsed['path'] ?? '';
        $base .= '?' . http_build_query( $clean_params );

        return $base;
    }

    /**
     * Sanitize a request or response body array for logging.
     *
     * Redacts sensitive keys and ensures values are scalar-safe.
     *
     * @param array $body The body array.
     *
     * @return array Sanitized copy.
     */
    private function sanitize_body( array $body ): array {
        return $this->redact_sensitive( $body );
    }

    /**
     * Recursively redact values for sensitive keys in an array.
     *
     * @param array $data The data to process.
     *
     * @return array Processed data with sensitive values replaced.
     */
    private function redact_sensitive( array $data ): array {
        $redacted = array();

        foreach ( $data as $key => $value ) {
            $lower_key = strtolower( (string) $key );

            // Check if this key matches any sensitive key.
            $is_sensitive = false;
            foreach ( self::SENSITIVE_KEYS as $sensitive ) {
                if ( str_contains( $lower_key, strtolower( $sensitive ) ) ) {
                    $is_sensitive = true;
                    break;
                }
            }

            if ( $is_sensitive ) {
                $redacted[ $key ] = '***REDACTED***';
            } elseif ( is_array( $value ) ) {
                $redacted[ $key ] = $this->redact_sensitive( $value );
            } else {
                $redacted[ $key ] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Truncate a string if it exceeds the max body length.
     *
     * @param string $text The text to potentially truncate.
     *
     * @return string Original or truncated text with indicator.
     */
    private function maybe_truncate( string $text ): string {
        if ( strlen( $text ) <= self::MAX_BODY_LENGTH ) {
            return $text;
        }

        return substr( $text, 0, self::MAX_BODY_LENGTH ) . '...[TRUNCATED]';
    }
}
