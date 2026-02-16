<?php
/**
 * GoHighLevel Gravity Add-On — Helper Functions
 *
 * Utility functions used across the plugin.
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check whether the current entry has already been synced to GHL.
 *
 * @param int $entry_id The Gravity Forms entry ID.
 *
 * @return bool
 */
function lh_ghl_is_entry_synced( int $entry_id ): bool {
    return (bool) gform_get_meta( $entry_id, 'lh_ghl_synced' );
}

/**
 * Mark an entry as synced to GHL.
 *
 * @param int $entry_id The Gravity Forms entry ID.
 *
 * @return void
 */
function lh_ghl_mark_entry_synced( int $entry_id ): void {
    gform_update_meta( $entry_id, 'lh_ghl_synced', true );
}

/**
 * Sanitize and validate an email address.
 *
 * @param string $email The raw email value.
 *
 * @return string|false Sanitized email or false if invalid.
 */
function lh_ghl_validate_email( string $email ): string|false {
    $email = sanitize_email( $email );

    if ( ! is_email( $email ) ) {
        return false;
    }

    return $email;
}

/**
 * Sanitize a mapped field value.
 *
 * @param mixed $value The raw value.
 *
 * @return string
 */
function lh_ghl_sanitize_field( mixed $value ): string {
    return sanitize_text_field( (string) $value );
}
