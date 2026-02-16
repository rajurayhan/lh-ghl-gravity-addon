<?php
/**
 * GoHighLevel API Client
 *
 * Handles all HTTP communication with the GoHighLevel (LeadConnector) API.
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LH_GHL_API
 *
 * Reusable wrapper around the GoHighLevel REST API.
 * Implements contact search/create/update, opportunity creation,
 * and pipeline retrieval with structured error handling and logging.
 */
class LH_GHL_API {

    /**
     * Base URL for the GHL API.
     *
     * @var string
     */
    private const BASE_URL = 'https://services.leadconnectorhq.com/';

    /**
     * API version header value.
     *
     * @var string
     */
    private const API_VERSION = '2021-07-28';

    /**
     * Default HTTP request timeout in seconds.
     *
     * @var int
     */
    private const TIMEOUT = 15;

    /**
     * API key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * Location ID.
     *
     * @var string
     */
    private string $location_id;

    /**
     * Logger instance.
     *
     * @var LH_GHL_Logger|null
     */
    private ?LH_GHL_Logger $logger;

    /**
     * Constructor.
     *
     * @param string             $api_key     The GHL API key.
     * @param string             $location_id The GHL Location ID.
     * @param LH_GHL_Logger|null $logger      Optional logger instance.
     */
    public function __construct( string $api_key, string $location_id, ?LH_GHL_Logger $logger = null ) {
        $this->api_key     = $api_key;
        $this->location_id = $location_id;
        $this->logger      = $logger;
    }

    // =========================================================================
    // Task 3.1 — Base HTTP Wrapper
    // =========================================================================

    /**
     * Build default request headers.
     *
     * Includes Authorization (Bearer), Content-Type, and API Version headers.
     *
     * @return array<string, string>
     */
    private function get_headers(): array {
        return array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Version'       => self::API_VERSION,
        );
    }

    /**
     * Perform an HTTP request to the GHL API.
     *
     * Supports GET, POST, and PUT methods. Automatically prepends the base URL,
     * attaches auth headers, logs the request/response, and parses the JSON body.
     *
     * @param string $method   HTTP method — 'GET', 'POST', or 'PUT'.
     * @param string $endpoint Relative API endpoint (e.g. 'contacts/').
     * @param array  $args     Optional. For GET: query parameters. For POST/PUT: body payload.
     *
     * @return array|WP_Error Decoded JSON response body on success, or WP_Error on failure.
     */
    private function request( string $method, string $endpoint, array $args = array() ): array|WP_Error {
        $url = self::BASE_URL . ltrim( $endpoint, '/' );

        // For GET requests, append query parameters to the URL.
        if ( 'GET' === $method && ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }

        // Log the outgoing request.
        if ( $this->logger ) {
            $this->logger->log_api_request( $method, $url, 'GET' !== $method ? $args : array() );
        }

        $request_args = array(
            'headers' => $this->get_headers(),
            'timeout' => self::TIMEOUT,
        );

        $start_time = microtime( true );

        if ( 'GET' === $method ) {
            $response = wp_remote_get( $url, $request_args );
        } else {
            // POST and PUT both use wp_remote_post; PUT overrides the method.
            $request_args['body']   = wp_json_encode( $args );
            $request_args['method'] = $method; // 'POST' or 'PUT'.
            $response               = wp_remote_post( $url, $request_args );
        }

        $elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 2 );

        // Handle transport-level errors (DNS failure, timeout, etc.).
        if ( is_wp_error( $response ) ) {
            $http_error = new WP_Error(
                'lh_ghl_http_error',
                sprintf(
                    /* translators: %s: error message */
                    __( 'HTTP request failed: %s', 'lh-ghl-gravity-addon' ),
                    $response->get_error_message()
                )
            );

            if ( $this->logger ) {
                $this->logger->log_failure(
                    'http_request',
                    sprintf( '%s %s failed after %.2fms', $method, $endpoint, $elapsed_ms ),
                    $http_error
                );
            }

            return $http_error;
        }

        return $this->parse_response( $response, $method, $endpoint, $elapsed_ms );
    }

    // =========================================================================
    // Task 3.7 — Error Handling & Response Parsing
    // =========================================================================

    /**
     * Parse an HTTP response from the GHL API.
     *
     * Validates the HTTP status code, decodes the JSON body, and converts
     * any error status into a WP_Error with a descriptive message.
     *
     * @param array|WP_Error $response   The raw response from wp_remote_*.
     * @param string         $method     The HTTP method used.
     * @param string         $endpoint   The API endpoint called.
     * @param float          $elapsed_ms Time taken for the request in milliseconds.
     *
     * @return array|WP_Error Decoded JSON body or WP_Error on failure.
     */
    private function parse_response( $response, string $method, string $endpoint, float $elapsed_ms ): array|WP_Error {
        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        // Log the response.
        if ( $this->logger ) {
            $this->logger->log_api_response( $status_code, $body, $elapsed_ms );
        }

        // Successful responses: 200, 201.
        if ( $status_code >= 200 && $status_code < 300 ) {
            return is_array( $body ) ? $body : array();
        }

        // Build a descriptive error message from the API response.
        $error_message = $this->extract_error_message( $body, $status_code );

        $error_code = match ( $status_code ) {
            401     => 'lh_ghl_unauthorized',
            400     => 'lh_ghl_bad_request',
            422     => 'lh_ghl_unprocessable',
            404     => 'lh_ghl_not_found',
            429     => 'lh_ghl_rate_limited',
            default => 'lh_ghl_api_error',
        };

        $api_error = new WP_Error( $error_code, $error_message );

        if ( $this->logger ) {
            $this->logger->log_failure(
                'api_response',
                sprintf( 'HTTP %d for %s %s (%.2fms)', $status_code, $method, $endpoint, $elapsed_ms ),
                $api_error
            );
        }

        return $api_error;
    }

    /**
     * Extract a human-readable error message from the API response body.
     *
     * The GHL API may return errors in several formats:
     * - { "message": "..." }
     * - { "error": "..." }
     * - { "msg": "..." }
     *
     * @param mixed $body        Decoded response body.
     * @param int   $status_code HTTP status code.
     *
     * @return string The error message.
     */
    private function extract_error_message( mixed $body, int $status_code ): string {
        if ( is_array( $body ) ) {
            if ( ! empty( $body['message'] ) ) {
                return sanitize_text_field( $body['message'] );
            }
            if ( ! empty( $body['error'] ) ) {
                return sanitize_text_field( $body['error'] );
            }
            if ( ! empty( $body['msg'] ) ) {
                return sanitize_text_field( $body['msg'] );
            }
        }

        return sprintf(
            /* translators: %d: HTTP status code */
            __( 'GHL API returned HTTP %d.', 'lh-ghl-gravity-addon' ),
            $status_code
        );
    }

    // =========================================================================
    // Test Connection (Milestone 2 — preserved)
    // =========================================================================

    /**
     * Test the API connection by making a lightweight request.
     *
     * Fetches a single contact to verify that the API key and Location ID are valid.
     *
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function test_connection(): true|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( 'Testing API connection...' );
        }

        $result = $this->request( 'GET', 'contacts/', array(
            'locationId' => $this->location_id,
            'limit'      => 1,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( $this->logger ) {
            $this->logger->info( 'API connection test successful.' );
        }

        return true;
    }

    // =========================================================================
    // Task 3.2 — Contact Search
    // =========================================================================

    /**
     * Search for a contact by email address.
     *
     * Uses the GHL duplicate search endpoint to find an existing contact.
     * Returns the contact data array if found, an empty array if no match,
     * or a WP_Error on failure.
     *
     * @param string $email The email address to search for.
     *
     * @return array|WP_Error Contact data array (with 'contact' key) or WP_Error.
     */
    public function search_contact( string $email ): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( sprintf( 'Searching for contact by email: %s', sanitize_email( $email ) ) );
        }

        if ( empty( $email ) ) {
            $error = new WP_Error(
                'lh_ghl_missing_email',
                __( 'Email address is required for contact search.', 'lh-ghl-gravity-addon' )
            );

            if ( $this->logger ) {
                $this->logger->log_validation_error( 'email', 'Email address is required for contact search.' );
            }

            return $error;
        }

        $result = $this->request( 'GET', 'contacts/search/duplicate', array(
            'locationId' => $this->location_id,
            'email'      => sanitize_email( $email ),
        ) );

        if ( is_wp_error( $result ) ) {
            // A 404 from the duplicate search means "no match" — not a real error.
            if ( 'lh_ghl_not_found' === $result->get_error_code() ) {
                return array();
            }
            return $result;
        }

        return $result;
    }

    // =========================================================================
    // Task 3.3 — Create Contact
    // =========================================================================

    /**
     * Create a new contact in GoHighLevel.
     *
     * Automatically injects the locationId into the payload.
     *
     * Expected $data keys (all optional except email):
     *   - firstName, lastName, email, phone, name
     *   - address1, city, state, postalCode, country
     *   - companyName, source, website, tags (array)
     *   - customFields (array of {id, value})
     *
     * @param array $data Contact data payload.
     *
     * @return array|WP_Error Created contact data on success, WP_Error on failure.
     */
    public function create_contact( array $data ): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( sprintf( 'Creating contact: %s', $data['email'] ?? 'unknown' ) );
        }

        // Ensure locationId is always present.
        $data['locationId'] = $this->location_id;

        return $this->request( 'POST', 'contacts/', $data );
    }

    // =========================================================================
    // Task 3.4 — Update Contact
    // =========================================================================

    /**
     * Update an existing contact in GoHighLevel.
     *
     * @param string $contact_id The GHL contact ID.
     * @param array  $data       Contact data payload (same structure as create_contact).
     *
     * @return array|WP_Error Updated contact data on success, WP_Error on failure.
     */
    public function update_contact( string $contact_id, array $data ): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( sprintf( 'Updating contact: %s', $contact_id ) );
        }

        if ( empty( $contact_id ) ) {
            $error = new WP_Error(
                'lh_ghl_missing_contact_id',
                __( 'Contact ID is required for update.', 'lh-ghl-gravity-addon' )
            );

            if ( $this->logger ) {
                $this->logger->log_validation_error( 'contact_id', 'Contact ID is required for update.' );
            }

            return $error;
        }

        return $this->request( 'PUT', 'contacts/' . urlencode( $contact_id ), $data );
    }

    // =========================================================================
    // Task 3.5 — Create Opportunity
    // =========================================================================

    /**
     * Create an opportunity in GoHighLevel.
     *
     * Automatically injects the locationId into the payload.
     *
     * Expected $data keys:
     *   - pipelineId      (string, required)
     *   - pipelineStageId (string, required)
     *   - contactId       (string, required)
     *   - name            (string, required)
     *   - monetaryValue   (float, optional)
     *   - assignedTo      (string, optional — user ID)
     *   - status          (string, optional — 'open', 'won', 'lost', 'abandoned')
     *   - source          (string, optional)
     *
     * @param array $data Opportunity data payload.
     *
     * @return array|WP_Error Created opportunity data on success, WP_Error on failure.
     */
    public function create_opportunity( array $data ): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info(
                sprintf( 'Creating opportunity: %s (pipeline: %s)', $data['name'] ?? 'unknown', $data['pipelineId'] ?? 'unknown' )
            );
        }

        // Validate required fields.
        $required = array( 'pipelineId', 'pipelineStageId', 'contactId', 'name' );
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                $message = sprintf(
                    /* translators: %s: field name */
                    __( 'Required opportunity field "%s" is missing.', 'lh-ghl-gravity-addon' ),
                    $field
                );

                if ( $this->logger ) {
                    $this->logger->log_validation_error( $field, $message, array( 'endpoint' => 'create_opportunity' ) );
                }

                return new WP_Error( 'lh_ghl_missing_field', $message );
            }
        }

        // Ensure locationId is always present.
        $data['locationId'] = $this->location_id;

        return $this->request( 'POST', 'opportunities/', $data );
    }

    // =========================================================================
    // Task 3.6 — Fetch Pipelines & Stages
    // =========================================================================

    /**
     * Fetch all pipelines (with their stages) for the current location.
     *
     * Returns an array with a 'pipelines' key containing the list of pipelines.
     * Each pipeline includes a 'stages' array.
     *
     * @return array|WP_Error Pipelines data or WP_Error on failure.
     */
    public function get_pipelines(): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( 'Fetching pipelines...' );
        }

        $result = $this->request( 'GET', 'opportunities/pipelines', array(
            'locationId' => $this->location_id,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }

    /**
     * Fetch stages for a specific pipeline.
     *
     * Convenience method that retrieves all pipelines and filters to the
     * requested one, returning only its stages.
     *
     * @param string $pipeline_id The pipeline ID to fetch stages for.
     *
     * @return array|WP_Error Array of stage objects or WP_Error.
     */
    public function get_pipeline_stages( string $pipeline_id ): array|WP_Error {
        $pipelines = $this->get_pipelines();

        if ( is_wp_error( $pipelines ) ) {
            return $pipelines;
        }

        $pipeline_list = $pipelines['pipelines'] ?? array();

        foreach ( $pipeline_list as $pipeline ) {
            if ( isset( $pipeline['id'] ) && $pipeline['id'] === $pipeline_id ) {
                return $pipeline['stages'] ?? array();
            }
        }

        return new WP_Error(
            'lh_ghl_pipeline_not_found',
            sprintf(
                /* translators: %s: pipeline ID */
                __( 'Pipeline "%s" not found.', 'lh-ghl-gravity-addon' ),
                $pipeline_id
            )
        );
    }

    // =========================================================================
    // Task — Fetch Location Custom Fields (Contacts)
    // =========================================================================

    /**
     * Fetch custom fields for the current location (contact custom fields).
     *
     * Uses GET /locations/:locationId/customFields. Returns an array with
     * a 'customFields' key containing the list (each item typically has id and name/label).
     *
     * @return array|WP_Error Array with 'customFields' key, or WP_Error on failure.
     */
    public function get_custom_fields(): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( 'Fetching location custom fields...' );
        }

        $endpoint = 'locations/' . $this->location_id . '/customFields';

        return $this->request( 'GET', $endpoint );
    }

    // =========================================================================
    // Task — Fetch Contact Object Schema (for field mapping)
    // =========================================================================

    /**
     * Fetch the contact object schema (all available contact fields).
     *
     * Uses GET /objects/contact with locationId. Returns the schema definition
     * including fields/properties that can be used to build the contact field map.
     *
     * @return array|WP_Error Schema data or WP_Error on failure.
     */
    public function get_contact_schema(): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( 'Fetching contact object schema...' );
        }

        return $this->request( 'GET', 'objects/contact', array(
            'locationId' => $this->location_id,
        ) );
    }

    // =========================================================================
    // Task — Fetch Location Users (for assignment)
    // =========================================================================

    /**
     * Fetch users for the current location (for opportunity assignee, etc.).
     *
     * Uses GET /users/ with locationId query. Returns an array with a 'users'
     * key containing the list (each item typically has id, name or firstName/lastName).
     *
     * @return array|WP_Error Array with 'users' key, or WP_Error on failure.
     */
    public function get_users(): array|WP_Error {
        if ( $this->logger ) {
            $this->logger->info( 'Fetching location users...' );
        }

        return $this->request( 'GET', 'users/', array(
            'locationId' => $this->location_id,
        ) );
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the Location ID.
     *
     * @return string
     */
    public function get_location_id(): string {
        return $this->location_id;
    }
}
