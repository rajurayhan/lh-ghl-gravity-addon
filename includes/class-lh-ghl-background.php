<?php
/**
 * GoHighLevel Background Processor
 *
 * Handles asynchronous processing of GHL sync tasks via
 * wp_schedule_single_event() to avoid blocking form submissions.
 *
 * Architecture:
 *   1. process_feed() in the main add-on performs quick validation
 *      (duplicate check, email validation) synchronously.
 *   2. It then calls LH_GHL_Background::schedule() which registers
 *      a one-time cron event with the entry and feed IDs.
 *   3. On the next WordPress cron tick, process() fires, re-fetches
 *      the entry/form/feed from the database, and delegates to
 *      LH_GHL_Addon::execute_sync() for the actual API work.
 *
 * This ensures form submissions return instantly while GHL sync
 * runs asynchronously.
 *
 * HTTP call budget per submission (max 3):
 *   - Search contact by email  (1 GET)
 *   - Create or update contact (1 POST/PUT)
 *   - Create opportunity       (1 POST, optional)
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LH_GHL_Background
 *
 * Background/async task handler using WordPress cron.
 */
class LH_GHL_Background {

    /**
     * Cron hook name for processing.
     *
     * @var string
     */
    public const CRON_HOOK = 'lh_ghl_process_entry';

    /**
     * Reference to the main add-on instance.
     *
     * @var LH_GHL_Addon|null
     */
    private static ?LH_GHL_Addon $addon = null;

    /**
     * Initialize background processing hooks.
     *
     * Registers the cron action callback and stores a reference
     * to the add-on instance for use during background execution.
     *
     * @param LH_GHL_Addon $addon The add-on instance.
     *
     * @return void
     */
    public static function init( LH_GHL_Addon $addon ): void {
        self::$addon = $addon;
        add_action( self::CRON_HOOK, array( __CLASS__, 'process' ), 10, 2 );
    }

    /**
     * Schedule a background sync task for an entry.
     *
     * Registers a one-time cron event that will fire on the next
     * WordPress cron tick. Only the entry ID and feed ID are passed
     * to keep the serialized payload small; the full entry, form,
     * and feed data are re-fetched from the database at execution time.
     *
     * @param int $entry_id The Gravity Forms entry ID.
     * @param int $feed_id  The feed ID.
     *
     * @return bool True if the event was scheduled, false on failure.
     */
    public static function schedule( int $entry_id, int $feed_id ): bool {
        $logger    = self::get_logger();
        $hook_args = array( $entry_id, $feed_id );

        // Prevent scheduling duplicate events for the same entry + feed.
        $next = wp_next_scheduled( self::CRON_HOOK, $hook_args );

        if ( false !== $next ) {
            if ( $logger ) {
                $logger->info(
                    sprintf( 'Background task already scheduled for Entry #%d / Feed #%d — skipping duplicate.', $entry_id, $feed_id )
                );
            }
            return false;
        }

        $scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, $hook_args );

        // wp_schedule_single_event() returns bool in WP 5.7+, void in older versions.
        if ( false === $scheduled ) {
            if ( $logger ) {
                $logger->error(
                    sprintf( 'Failed to schedule background task for Entry #%d / Feed #%d.', $entry_id, $feed_id )
                );
            }
            return false;
        }

        if ( $logger ) {
            $logger->info(
                sprintf( 'Background task scheduled for Entry #%d / Feed #%d.', $entry_id, $feed_id )
            );
        }

        // Trigger cron in a separate request so the sync runs shortly after submit
        // without requiring another page load or manual wp-cron.php hit.
        self::spawn_cron();

        return true;
    }

    /**
     * Trigger WordPress cron via a non-blocking request to wp-cron.php.
     *
     * Ensures the scheduled GHL sync runs shortly after form submission even on
     * low-traffic sites or when DISABLE_WP_CRON is not used with system cron.
     * The request is fire-and-forget (blocking false, short timeout) so the
     * form response is not delayed.
     *
     * @return void
     */
    public static function spawn_cron(): void {
        $url = add_query_arg( 'doing_wp_cron', microtime( true ), home_url( 'wp-cron.php' ) );

        wp_remote_get(
            $url,
            array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'lh_ghl_https_local_ssl_verify', false ), // Filter to allow enabling SSL verify for local cron (default false).
            )
        );
    }

    /**
     * Process a background sync task.
     *
     * Called by WordPress cron. Re-fetches the entry, form, and feed
     * from the database to ensure fresh data, then delegates to the
     * add-on's execute_sync() method for the actual API work.
     *
     * @param int $entry_id The Gravity Forms entry ID.
     * @param int $feed_id  The feed ID.
     *
     * @return void
     */
    public static function process( int $entry_id, int $feed_id ): void {
        $addon  = self::get_addon_instance();
        $logger = self::get_logger();

        if ( $logger ) {
            $logger->info(
                sprintf( '--- Background Task Start --- Entry #%d | Feed #%d', $entry_id, $feed_id )
            );
        }

        // Validate add-on availability.
        if ( null === $addon ) {
            if ( $logger ) {
                $logger->error( 'Add-on instance not available — cannot process background task.' );
            }
            return;
        }

        // ----- Re-fetch entry from database -----
        $entry = GFAPI::get_entry( $entry_id );

        if ( is_wp_error( $entry ) ) {
            if ( $logger ) {
                $logger->error(
                    sprintf( 'Failed to fetch Entry #%d: %s', $entry_id, $entry->get_error_message() )
                );
            }
            return;
        }

        // ----- Re-fetch form from database -----
        $form_id = (int) rgar( $entry, 'form_id' );
        $form    = GFAPI::get_form( $form_id );

        if ( ! $form ) {
            if ( $logger ) {
                $logger->error(
                    sprintf( 'Failed to fetch Form #%d for Entry #%d.', $form_id, $entry_id )
                );
            }
            return;
        }

        // ----- Re-fetch feed from database -----
        $feed = $addon->get_feed( $feed_id );

        if ( ! $feed ) {
            if ( $logger ) {
                $logger->error(
                    sprintf( 'Feed #%d not found — it may have been deleted.', $feed_id )
                );
            }
            return;
        }

        // Check that the feed is still active.
        if ( ! rgar( $feed, 'is_active' ) ) {
            if ( $logger ) {
                $logger->info(
                    sprintf( 'Feed #%d is inactive — skipping background sync.', $feed_id )
                );
            }
            return;
        }

        // ----- Delegate to the add-on's sync logic -----
        $addon->execute_sync( $feed, $entry, $form );

        if ( $logger ) {
            $logger->info(
                sprintf( '--- Background Task End --- Entry #%d | Feed #%d', $entry_id, $feed_id )
            );
        }
    }

    /**
     * Get the add-on instance.
     *
     * Uses the stored reference from init(), falling back to the
     * global helper function if the static reference is not set
     * (e.g. when running from a cron context where init order differs).
     *
     * @return LH_GHL_Addon|null
     */
    private static function get_addon_instance(): ?LH_GHL_Addon {
        if ( null !== self::$addon ) {
            return self::$addon;
        }

        // Fallback: try to get the instance via the global helper.
        if ( function_exists( 'lh_ghl_addon_get_instance' ) ) {
            $instance = lh_ghl_addon_get_instance();

            if ( $instance instanceof LH_GHL_Addon ) {
                self::$addon = $instance;
                return $instance;
            }
        }

        return null;
    }

    /**
     * Get the logger from the add-on instance.
     *
     * @return LH_GHL_Logger|null
     */
    private static function get_logger(): ?LH_GHL_Logger {
        $addon = self::get_addon_instance();
        return $addon ? $addon->get_logger() : null;
    }
}
