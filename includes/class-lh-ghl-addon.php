<?php
/**
 * GoHighLevel Gravity Forms Add-On — Main Add-On Class
 *
 * Extends GFFeedAddOn to provide feed-based integration
 * with GoHighLevel (LeadConnector API).
 *
 * @package RAKAAITECH_GHL_Gravity_Addon
 * @author  RakaAITech <https://rakaaitech.com>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

GFForms::include_feed_addon_framework();

/**
 * Class LH_GHL_Addon
 *
 * Main add-on class registered with Gravity Forms.
 */
class LH_GHL_Addon extends GFFeedAddOn {

    /**
     * Plugin version.
     *
     * @var string
     */
    protected $_version = RAKAAITECH_GHL_ADDON_VERSION;

    /**
     * Minimum Gravity Forms version required.
     *
     * @var string
     */
    protected $_min_gravityforms_version = RAKAAITECH_GHL_ADDON_MIN_GF_VERSION;

    /**
     * Add-on slug used for options, paths, etc.
     *
     * @var string
     */
    protected $_slug = 'lh-ghl-gravity-addon';

    /**
     * Relative path to the plugin from the plugins folder.
     *
     * @var string
     */
    protected $_path = 'lh-ghl-gravity-addon/lh-ghl-gravity-addon.php';

    /**
     * Full path to this file.
     *
     * @var string
     */
    protected $_full_path = __FILE__;

    /**
     * Title of the add-on displayed in admin.
     *
     * @var string
     */
    protected $_title = 'GoHighLevel Gravity Add-On';

    /**
     * Short title used in menus.
     *
     * @var string
     */
    protected $_short_title = 'GoHighLevel';

    /**
     * Singleton instance.
     *
     * @var LH_GHL_Addon|null
     */
    private static $_instance = null;

    /**
     * API client instance.
     *
     * @var LH_GHL_API|null
     */
    private ?LH_GHL_API $api = null;

    /**
     * Logger instance.
     *
     * @var LH_GHL_Logger|null
     */
    private ?LH_GHL_Logger $logger = null;

    /**
     * Get singleton instance.
     *
     * @return LH_GHL_Addon
     */
    public static function get_instance(): self {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Run pre-initialization tasks.
     *
     * @return void
     */
    public function pre_init(): void {
        parent::pre_init();
    }

    /**
     * Initialize the add-on.
     *
     * @return void
     */
    public function init(): void {
        parent::init();

        // Initialize logger.
        $this->logger = new LH_GHL_Logger( $this );

        // Initialize background processor.
        LH_GHL_Background::init( $this );

        // Frontend: populate dropdown/radio choices from GHL when field has class ghl-choices-*.
        add_filter( 'gform_field_choices', array( $this, 'filter_field_choices_from_ghl' ), 10, 3 );
    }

    /**
     * Return the API client instance, creating it if needed.
     *
     * @return LH_GHL_API|null Returns null if API key or Location ID is not configured.
     */
    public function get_api(): ?LH_GHL_API {
        if ( null !== $this->api ) {
            return $this->api;
        }

        $api_key     = $this->get_plugin_setting( 'lh_ghl_api_key' );
        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );

        if ( empty( $api_key ) || empty( $location_id ) ) {
            return null;
        }

        $this->api = new LH_GHL_API( $api_key, $location_id, $this->logger );
        return $this->api;
    }

    /**
     * Return the logger instance.
     *
     * @return LH_GHL_Logger|null
     */
    public function get_logger(): ?LH_GHL_Logger {
        return $this->logger;
    }

    // -------------------------------------------------------------------------
    // Admin Initialization
    // -------------------------------------------------------------------------

    /**
     * Admin-specific initialization — register AJAX handlers.
     *
     * @return void
     */
    public function init_admin(): void {
        parent::init_admin();

        // Test Connection AJAX is registered in the main plugin bootstrap so it runs on admin-ajax.php
        // (GF addon does not call init_admin() when RG_CURRENT_PAGE is admin-ajax.php).
        add_action( 'wp_ajax_lh_ghl_get_stages', array( $this, 'ajax_get_stages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_test_connection_script' ), 10 );
    }

    /**
     * Whether the current user can access the add-on plugin settings page.
     *
     * Used by the plugin action link (Settings) on the Plugins list.
     *
     * @return bool
     */
    public function current_user_can_plugin_settings(): bool {
        return $this->current_user_can_any( $this->_capabilities_settings_page );
    }

    // -------------------------------------------------------------------------
    // Plugin Settings (Global) — Milestone 2
    // -------------------------------------------------------------------------

    /**
     * Define plugin-level settings fields.
     *
     * Fields: API Key (password), Location ID, Default Lead Source, Debug Mode,
     * and a Test Connection button.
     *
     * @return array
     */
    public function plugin_settings_fields(): array {
        return array(
            array(
                'title'       => esc_html__( 'GoHighLevel API Settings', 'lh-ghl-gravity-addon' ),
                'description' => esc_html__( 'Configure your GoHighLevel API credentials and plugin options.', 'lh-ghl-gravity-addon' ),
                'fields'      => array(
                    array(
                        'name'              => 'lh_ghl_api_key',
                        'label'             => esc_html__( 'API Key', 'lh-ghl-gravity-addon' ),
                        'type'              => 'text',
                        'input_type'        => 'password',
                        'class'             => 'medium',
                        'required'          => true,
                        'feedback_callback' => array( $this, 'is_valid_api_key' ),
                        'tooltip'           => esc_html__( 'Enter your GoHighLevel (LeadConnector) API key. This key is stored securely and never exposed in frontend code.', 'lh-ghl-gravity-addon' ),
                    ),
                    array(
                        'name'     => 'lh_ghl_location_id',
                        'label'    => esc_html__( 'Location ID', 'lh-ghl-gravity-addon' ),
                        'type'     => 'text',
                        'class'    => 'medium',
                        'required' => true,
                        'tooltip'  => esc_html__( 'Enter your GoHighLevel Location ID.', 'lh-ghl-gravity-addon' ),
                    ),
                    array(
                        'name'          => 'lh_ghl_default_lead_source',
                        'label'         => esc_html__( 'Default Lead Source', 'lh-ghl-gravity-addon' ),
                        'type'          => 'text',
                        'class'         => 'medium',
                        'default_value' => 'Gravity Forms',
                        'tooltip'       => esc_html__( 'Default source value applied to new contacts (e.g. "Gravity Forms").', 'lh-ghl-gravity-addon' ),
                    ),
                    array(
                        'name'    => 'lh_ghl_debug_mode',
                        'label'   => esc_html__( 'Debug Mode', 'lh-ghl-gravity-addon' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Enable verbose debug logging', 'lh-ghl-gravity-addon' ),
                                'name'  => 'lh_ghl_debug_mode',
                            ),
                        ),
                        'tooltip' => esc_html__( 'When enabled, detailed logs are written for every API interaction. Disable in production for performance.', 'lh-ghl-gravity-addon' ),
                    ),
                    array(
                        'name'  => 'lh_ghl_test_connection',
                        'label' => esc_html__( 'API Connection', 'lh-ghl-gravity-addon' ),
                        'type'  => 'html',
                        'html'  => array( $this, 'get_test_connection_markup' ),
                    ),
                ),
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Secure Storage — autoload=false (Task 2.2)
    // -------------------------------------------------------------------------

    /**
     * Override to store plugin settings with autoload disabled.
     *
     * Ensures the API key is not loaded into memory on every WordPress page load.
     *
     * @param array $settings The settings to save.
     *
     * @return void
     */
    public function update_plugin_settings( $settings ): void {
        // Sanitize all settings before persisting.
        $settings = $this->sanitize_plugin_settings( $settings );

        // Save with autoload=false so the API key isn't loaded on every page request.
        $option_name = 'gravityformsaddon_' . $this->get_slug() . '_settings';
        update_option( $option_name, $settings, false );
    }

    // -------------------------------------------------------------------------
    // Input Sanitization (Task 2.3)
    // -------------------------------------------------------------------------

    /**
     * Sanitize plugin settings before saving.
     *
     * @param array $settings The raw settings array.
     *
     * @return array Sanitized settings.
     */
    private function sanitize_plugin_settings( array $settings ): array {
        if ( isset( $settings['lh_ghl_api_key'] ) ) {
            $settings['lh_ghl_api_key'] = sanitize_text_field( $settings['lh_ghl_api_key'] );
        }

        if ( isset( $settings['lh_ghl_location_id'] ) ) {
            $settings['lh_ghl_location_id'] = sanitize_text_field( $settings['lh_ghl_location_id'] );
        }

        if ( isset( $settings['lh_ghl_default_lead_source'] ) ) {
            $settings['lh_ghl_default_lead_source'] = sanitize_text_field( $settings['lh_ghl_default_lead_source'] );
        }

        // Debug mode checkbox — normalize to '1' or '0'.
        $settings['lh_ghl_debug_mode'] = ! empty( $settings['lh_ghl_debug_mode'] ) ? '1' : '0';

        return $settings;
    }

    // -------------------------------------------------------------------------
    // API Key Validation / Test Connection (Task 2.4)
    // -------------------------------------------------------------------------

    /**
     * Feedback callback for the API Key field.
     *
     * Called by Gravity Forms after save to display a checkmark or X icon.
     *
     * @param string $value The saved API key value.
     *
     * @return bool|null True = valid (green check), false = invalid (red X), null = skip icon.
     */
    public function is_valid_api_key( $value ): ?bool {
        if ( empty( $value ) ) {
            return null;
        }

        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );

        if ( empty( $location_id ) ) {
            // Cannot validate without a Location ID — skip the indicator.
            return null;
        }

        $api    = new LH_GHL_API( $value, $location_id );
        $result = $api->test_connection();

        return ! is_wp_error( $result );
    }

    /**
     * AJAX handler — Test Connection to GoHighLevel.
     *
     * Verifies the saved API Key and Location ID by making a lightweight API call.
     *
     * @return void
     */
    public function ajax_test_connection(): void {
        // Verify nonce (return JSON so the client can show a message instead of "Request failed").
        if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lh_ghl_test_connection' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'lh-ghl-gravity-addon' ) ) );
        }

        // Verify capability.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'lh-ghl-gravity-addon' ) ) );
        }

        $api_key     = $this->get_plugin_setting( 'lh_ghl_api_key' );
        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );

        if ( empty( $api_key ) || empty( $location_id ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Please save your API Key and Location ID before testing the connection.', 'lh-ghl-gravity-addon' ) )
            );
        }

        $api    = new LH_GHL_API( $api_key, $location_id );
        $result = $api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => wp_strip_all_tags( $result->get_error_message() ) ) );
        }

        wp_send_json_success(
            array( 'message' => esc_html__( 'Connection successful! API key and Location ID are valid.', 'lh-ghl-gravity-addon' ) )
        );
    }

    /**
     * Enqueue the Test Connection inline script when on the add-on plugin settings page.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     *
     * @return void
     */
    public function maybe_enqueue_test_connection_script( string $hook_suffix ): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== 'forms_page_gf_settings' ) {
            return;
        }
        // Subview identifies which add-on tab is active; not form submission data.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['subview'] ) || sanitize_key( (string) $_GET['subview'] ) !== $this->_slug ) {
            return;
        }
        $nonce  = wp_create_nonce( 'lh_ghl_test_connection' );
        $script = $this->get_test_connection_script( $nonce );
        wp_add_inline_script( 'jquery', $script, 'after' );
    }

    /**
     * Return the Test Connection button and result span (used by Settings API html field).
     *
     * Renders on the plugin settings page when the new Settings renderer is used.
     * Also used by the legacy settings_lh_ghl_test_connection_button renderer when applicable.
     *
     * @return string HTML markup for the button and result span (script enqueued separately).
     */
    public function get_test_connection_markup(): string {
        $html  = '<button type="button" id="lh-ghl-test-connection" class="button-secondary">';
        $html .= esc_html__( 'Test Connection', 'lh-ghl-gravity-addon' );
        $html .= '</button>';
        $html .= '<span id="lh-ghl-test-connection-result" style="margin-left: 10px;"></span>';
        return $html;
    }

    /**
     * Render the custom "Test Connection" settings field (legacy path when Settings renderer not used).
     *
     * @param array $field The field properties.
     * @param bool  $echo  Whether to echo the output.
     *
     * @return string The rendered HTML.
     */
    public function settings_lh_ghl_test_connection_button( $field, $echo = true ): string {
        $html = $this->get_test_connection_markup();
        if ( $echo ) {
            echo wp_kses_post( $html );
        }
        return $html;
    }

    /**
     * Return the inline JavaScript for the Test Connection button (raw JS for wp_add_inline_script).
     *
     * @param string $nonce The security nonce.
     *
     * @return string Inline script content (no script tag wrapper).
     */
    private function get_test_connection_script( string $nonce ): string {
        $testing_text = esc_js( __( 'Testing…', 'lh-ghl-gravity-addon' ) );
        $button_text  = esc_js( __( 'Test Connection', 'lh-ghl-gravity-addon' ) );
        $fail_text    = esc_js( __( 'Request failed. Please try again.', 'lh-ghl-gravity-addon' ) );
        $nonce_js     = esc_js( $nonce );

        $js = 'jQuery(document).ready(function($) {';
        $js .= '  $("#lh-ghl-test-connection").on("click", function() {';
        $js .= '    var $btn = $(this);';
        $js .= '    var $result = $("#lh-ghl-test-connection-result");';
        $js .= '    $btn.prop("disabled", true).text("' . $testing_text . '");';
        $js .= '    $result.html("");';
        $js .= '    var ajaxUrl = typeof ajaxurl !== "undefined" ? ajaxurl : "";';
        $js .= '    if (!ajaxUrl) {';
        $js .= '      $result.html(\'<span style="color:red;font-weight:bold;">\u2717 \' + "' . $fail_text . '" + \'</span>\');';
        $js .= '      $btn.prop("disabled", false).text("' . $button_text . '");';
        $js .= '      return;';
        $js .= '    }';
        $js .= '    $.post(ajaxUrl, { action: "lh_ghl_test_connection", nonce: "' . $nonce_js . '" }, function(response) {';
        $js .= '      var msg = (response && response.data && response.data.message) ? response.data.message : "' . $fail_text . '";';
        $js .= '      var safeMsg = $("<span>").text(msg).html();';
        $js .= '      if (response && response.success) {';
        $js .= '        $result.html(\'<span style="color:green;font-weight:bold;">\u2713 \' + safeMsg + \'</span>\');';
        $js .= '      } else {';
        $js .= '        $result.html(\'<span style="color:red;font-weight:bold;">\u2717 \' + safeMsg + \'</span>\');';
        $js .= '      }';
        $js .= '      $btn.prop("disabled", false).text("' . $button_text . '");';
        $js .= '    }).fail(function(jqXHR, textStatus, errorThrown) {';
        $js .= '      var errMsg = "' . $fail_text . '";';
        $js .= '      if (jqXHR && jqXHR.status === 403) { errMsg = "Security check failed. Refresh the page and try again."; }';
        $js .= '      else if (jqXHR && jqXHR.status) { errMsg = "Request failed (HTTP " + jqXHR.status + "). Check console or try again."; }';
        $js .= '      else if (textStatus === "timeout") { errMsg = "Request timed out. Check your server or try again."; }';
        $js .= '      $result.html(\'<span style="color:red;font-weight:bold;">\u2717 \' + errMsg + \'</span>\');';
        $js .= '      $btn.prop("disabled", false).text("' . $button_text . '");';
        $js .= '    });';
        $js .= '  });';
        $js .= '});';
        return $js;
    }

    // -------------------------------------------------------------------------
    // Feed Settings (Per-Form) — Milestone 5
    // -------------------------------------------------------------------------

    /**
     * Check if feed creation is possible.
     *
     * Requires API Key and Location ID to be configured in plugin settings.
     *
     * @return bool
     */
    public function can_create_feed(): bool {
        $api_key     = $this->get_plugin_setting( 'lh_ghl_api_key' );
        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );

        return ! empty( $api_key ) && ! empty( $location_id );
    }

    /**
     * Define feed-level settings fields.
     *
     * Sections:
     * 1. Feed name
     * 2. Contact field mapping (First Name, Last Name, Email, Phone)
     * 3. Custom fields mapping (generic map for GHL custom fields)
     * 4. Tags
     * 5. Opportunity toggle
     * 6. Opportunity settings (Pipeline, Stage, Name, Value, Assign To, Status)
     * 7. Conditional logic
     *
     * @return array
     */
    public function feed_settings_fields(): array {
        return array(
            // Section 1: Feed Name.
            array(
                'id'          => 'lh-ghl-section-feed-name',
                'title'       => esc_html__( 'GoHighLevel Feed Settings', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'     => 'feedName',
                        'label'    => esc_html__( 'Feed Name', 'lh-ghl-gravity-addon' ),
                        'type'     => 'text',
                        'required' => true,
                        'class'    => 'medium',
                        'tooltip'  => esc_html__( 'Enter a descriptive name for this feed.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
            // Section 2: Contact Field Mapping (form field or custom value per contact field).
            array(
                'id'          => 'lh-ghl-section-contact-mapping',
                'title'       => esc_html__( 'Contact Field Mapping', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'                => 'contactFieldMap',
                        'label'               => esc_html__( 'Map Fields', 'lh-ghl-gravity-addon' ),
                        'type'                => 'generic_map',
                        'key_choices'         => $this->get_contact_field_map(),
                        'enable_custom_value' => true,
                        'key_field_title'     => esc_html__( 'GHL Contact Field', 'lh-ghl-gravity-addon' ),
                        'value_field_title'   => esc_html__( 'Form Field or Custom Value', 'lh-ghl-gravity-addon' ),
                        'tooltip'             => esc_html__( 'Map form fields or enter a custom value (merge tags supported) for each GoHighLevel contact field. Email is required.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
            // Section 3: Custom Fields (GHL custom fields loaded from API; user selects by name).
            array(
                'id'          => 'lh-ghl-section-custom-fields',
                'title'       => esc_html__( 'Custom Fields', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'              => 'customFieldMap',
                        'label'             => esc_html__( 'Custom Field Mapping', 'lh-ghl-gravity-addon' ),
                        'type'              => 'generic_map',
                        'key_choices'       => $this->get_custom_field_choices(),
                        'key_field_title'   => esc_html__( 'GHL Custom Field', 'lh-ghl-gravity-addon' ),
                        'value_field_title' => esc_html__( 'Form Field', 'lh-ghl-gravity-addon' ),
                        'tooltip'           => esc_html__( 'Map form fields to GoHighLevel contact custom fields. Select the GHL custom field on the left and the form field or custom value on the right. Custom fields are loaded from your GHL location.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
            // Section 4: Tags.
            array(
                'id'          => 'lh-ghl-section-tags',
                'title'       => esc_html__( 'Tags', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'    => 'contactTags',
                        'label'   => esc_html__( 'Tags', 'lh-ghl-gravity-addon' ),
                        'type'    => 'text',
                        'class'   => 'medium merge-tag-support mt-position-right',
                        'tooltip' => esc_html__( 'Enter comma-separated tags to assign to the contact in GoHighLevel. Merge tags are supported.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
            // Section 5: Opportunity Toggle.
            array(
                'id'          => 'lh-ghl-section-opportunity-toggle',
                'title'       => esc_html__( 'Opportunity', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'    => 'enableOpportunity',
                        'label'   => esc_html__( 'Create Opportunity', 'lh-ghl-gravity-addon' ),
                        'type'    => 'checkbox',
                        'choices' => array(
                            array(
                                'label' => esc_html__( 'Enable opportunity creation for this feed', 'lh-ghl-gravity-addon' ),
                                'name'  => 'enableOpportunity',
                            ),
                        ),
                        'tooltip' => esc_html__( 'When enabled, an opportunity will be created in the selected pipeline alongside the contact.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
            // Section 6: Opportunity Settings (always visible so configs can be set when adding a new feed).
            array(
                'id'          => 'lh-ghl-section-opportunity-settings',
                'title'       => esc_html__( 'Opportunity Settings', 'lh-ghl-gravity-addon' ),
                'collapsible'  => true,
                'fields'      => $this->get_opportunity_settings_fields(),
            ),
            // Section 7: Conditional Logic.
            array(
                'id'          => 'lh-ghl-section-conditional-logic',
                'title'       => esc_html__( 'Conditional Logic', 'lh-ghl-gravity-addon' ),
                'collapsible' => true,
                'fields'      => array(
                    array(
                        'name'           => 'feedCondition',
                        'label'          => esc_html__( 'Conditional Logic', 'lh-ghl-gravity-addon' ),
                        'type'           => 'feed_condition',
                        'checkbox_label' => esc_html__( 'Enable', 'lh-ghl-gravity-addon' ),
                        'instructions'   => esc_html__( 'Process this feed if', 'lh-ghl-gravity-addon' ),
                        'tooltip'        => esc_html__( 'When enabled, this feed will only be processed when the specified conditions are met.', 'lh-ghl-gravity-addon' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Validate feed settings.
     *
     * When "Enable opportunity creation for this feed" is off, the Opportunity
     * Settings section is excluded from validation. When it is on, Pipeline and
     * Stage are required and validated here.
     *
     * @param array $fields   Sections/fields from feed_settings_fields().
     * @param array $settings Posted settings.
     * @return bool
     */
    public function validate_settings( $fields, $settings ) {
        $enable_opportunity = rgar( $settings, 'enableOpportunity' );
        if ( rgblank( $enable_opportunity ) ) {
            $fields = array_filter( $fields, function ( $section ) {
                return rgar( $section, 'id' ) !== 'lh-ghl-section-opportunity-settings';
            } );
        }
        $valid = parent::validate_settings( $fields, $settings );
        if ( $valid && ! rgblank( $enable_opportunity ) ) {
            $pipeline = rgar( $settings, 'opportunityPipeline' );
            $stage    = rgar( $settings, 'opportunityStage' );
            if ( rgblank( $pipeline ) || rgblank( $stage ) ) {
                foreach ( $fields as &$section ) {
                    if ( rgar( $section, 'id' ) !== 'lh-ghl-section-opportunity-settings' ) {
                        continue;
                    }
                    foreach ( $section['fields'] as &$field ) {
                        $name = rgar( $field, 'name' );
                        if ( $name === 'opportunityPipeline' && rgblank( $pipeline ) ) {
                            $this->set_field_error( $field, esc_html__( 'Please select a pipeline.', 'lh-ghl-gravity-addon' ) );
                        }
                        if ( $name === 'opportunityStage' && rgblank( $stage ) ) {
                            $this->set_field_error( $field, esc_html__( 'Please select a stage.', 'lh-ghl-gravity-addon' ) );
                        }
                    }
                    break;
                }
                $valid = false;
            }
        }
        return $valid;
    }

    /**
     * Return the default (fallback) contact field map when the API is unavailable.
     *
     * @return array
     */
    private function get_default_contact_field_map(): array {
        return array(
            array( 'name' => 'firstName', 'label' => esc_html__( 'First Name', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'lastName', 'label' => esc_html__( 'Last Name', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'name', 'label' => esc_html__( 'Full Name', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'email', 'label' => esc_html__( 'Email', 'lh-ghl-gravity-addon' ), 'required' => true ),
            array( 'name' => 'phone', 'label' => esc_html__( 'Phone', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'address1', 'label' => esc_html__( 'Address (Street)', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'city', 'label' => esc_html__( 'City', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'state', 'label' => esc_html__( 'State', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'postalCode', 'label' => esc_html__( 'Postal / ZIP Code', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'country', 'label' => esc_html__( 'Country', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'companyName', 'label' => esc_html__( 'Company Name', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'website', 'label' => esc_html__( 'Website', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'dateOfBirth', 'label' => esc_html__( 'Date of Birth', 'lh-ghl-gravity-addon' ), 'required' => false ),
            array( 'name' => 'source', 'label' => esc_html__( 'Lead Source', 'lh-ghl-gravity-addon' ), 'required' => false ),
        );
    }

    /**
     * Return the contact field map definition (from GHL API when possible).
     *
     * Fetches the contact object schema from the API and builds the field map
     * from it. Results are cached for 5 minutes. Falls back to default fields
     * if the API fails or returns an unexpected structure. Email is always
     * required for the addon flow.
     *
     * @return array
     */
    private function get_contact_field_map(): array {
        $api = $this->get_api();
        if ( null === $api ) {
            return $this->get_default_contact_field_map();
        }

        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );
        $cache_key   = 'lh_ghl_contact_schema_' . md5( $location_id );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $result = $api->get_contact_schema();
        if ( is_wp_error( $result ) ) {
            return $this->get_default_contact_field_map();
        }

        $fields = $this->parse_contact_schema_to_field_map( $result );
        if ( ! empty( $fields ) ) {
            set_transient( $cache_key, $fields, 5 * MINUTE_IN_SECONDS );
            return $fields;
        }

        return $this->get_default_contact_field_map();
    }

    /**
     * Parse GHL contact schema API response into GF field_map format.
     *
     * Handles common response shapes: schema.fields, object.fields, fields.
     * Each field expects key/name/id and label/displayName/title.
     *
     * @param array $result Raw API response.
     * @return array List of { name, label, required } for field_map.
     */
    private function parse_contact_schema_to_field_map( array $result ): array {
        $list = $result['schema']['fields'] ?? $result['object']['fields'] ?? $result['fields'] ?? null;
        if ( ! is_array( $list ) ) {
            // Some APIs return properties as object keyed by field name.
            $props = $result['schema']['properties'] ?? $result['object']['properties'] ?? $result['properties'] ?? null;
            if ( is_array( $props ) ) {
                $list = array();
                foreach ( $props as $key => $def ) {
                    $list[] = array_merge( is_array( $def ) ? $def : array(), array( 'key' => $key, 'name' => $key ) );
                }
            }
        }
        if ( empty( $list ) || ! is_array( $list ) ) {
            return array();
        }

        $fields = array();
        $seen   = array();
        foreach ( $list as $field ) {
            $key = $field['key'] ?? $field['name'] ?? $field['id'] ?? $field['fieldKey'] ?? null;
            if ( empty( $key ) || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $label = $field['label'] ?? $field['displayName'] ?? $field['title'] ?? $field['name'] ?? $key;
            if ( is_string( $label ) ) {
                $label = esc_html( $label );
            }
            $fields[] = array(
                'name'     => (string) $key,
                'label'    => $label,
                'required' => strtolower( (string) $key ) === 'email',
            );
        }

        return $fields;
    }

    /**
     * Return the opportunity settings fields.
     *
     * Includes Pipeline (dynamic from API), Stage (dependent on Pipeline),
     * Opportunity Name (merge tags), Monetary Value, Assign To, and Status.
     *
     * @return array
     */
    private function get_opportunity_settings_fields(): array {
        return array(
            array(
                'name'     => 'opportunityPipeline',
                'label'    => esc_html__( 'Pipeline', 'lh-ghl-gravity-addon' ),
                'type'     => 'select',
                'choices'  => $this->get_pipeline_choices(),
                'onchange' => "jQuery(this).parents('form').submit();",
                'tooltip'  => esc_html__( 'Select the pipeline for the opportunity. Changing this will save the feed and reload available stages.', 'lh-ghl-gravity-addon' ),
            ),
            array(
                'name'    => 'opportunityStage',
                'label'   => esc_html__( 'Stage', 'lh-ghl-gravity-addon' ),
                'type'    => 'select',
                'choices' => $this->get_stage_choices(),
                'tooltip' => esc_html__( 'Select the stage within the selected pipeline.', 'lh-ghl-gravity-addon' ),
            ),
            array(
                'name'    => 'opportunityName',
                'label'   => esc_html__( 'Opportunity Name', 'lh-ghl-gravity-addon' ),
                'type'    => 'text',
                'class'   => 'medium merge-tag-support mt-position-right',
                'tooltip' => esc_html__( 'Enter a name for the opportunity. Merge tags are supported (e.g. {First Name:1} - New Lead).', 'lh-ghl-gravity-addon' ),
            ),
            array(
                'name'    => 'opportunityValue',
                'label'   => esc_html__( 'Monetary Value', 'lh-ghl-gravity-addon' ),
                'type'    => 'text',
                'class'   => 'medium merge-tag-support mt-position-right',
                'tooltip' => esc_html__( 'Enter a static monetary value or use a merge tag to map from a form field.', 'lh-ghl-gravity-addon' ),
            ),
            array(
                'name'    => 'opportunityAssignTo',
                'label'   => esc_html__( 'Assign To', 'lh-ghl-gravity-addon' ),
                'type'    => 'select',
                'choices' => $this->get_user_choices(),
                'tooltip' => esc_html__( 'Select a user in your GHL location to assign the opportunity to (optional). Users are loaded from your location.', 'lh-ghl-gravity-addon' ),
            ),
            array(
                'name'          => 'opportunityStatus',
                'label'         => esc_html__( 'Status', 'lh-ghl-gravity-addon' ),
                'type'          => 'select',
                'default_value' => 'open',
                'choices'       => array(
                    array(
                        'label' => esc_html__( 'Open', 'lh-ghl-gravity-addon' ),
                        'value' => 'open',
                    ),
                    array(
                        'label' => esc_html__( 'Won', 'lh-ghl-gravity-addon' ),
                        'value' => 'won',
                    ),
                    array(
                        'label' => esc_html__( 'Lost', 'lh-ghl-gravity-addon' ),
                        'value' => 'lost',
                    ),
                    array(
                        'label' => esc_html__( 'Abandoned', 'lh-ghl-gravity-addon' ),
                        'value' => 'abandoned',
                    ),
                ),
                'tooltip' => esc_html__( 'Set the initial status of the opportunity.', 'lh-ghl-gravity-addon' ),
            ),
        );
    }

    /**
     * Get pipeline dropdown choices from the GHL API.
     *
     * Fetches pipelines and formats them as choices for the select field.
     * Results are cached in a transient for 5 minutes to reduce API calls.
     *
     * @return array
     */
    private function get_pipeline_choices(): array {
        $choices = array(
            array(
                'label' => esc_html__( '— Select a Pipeline —', 'lh-ghl-gravity-addon' ),
                'value' => '',
            ),
        );

        $api = $this->get_api();
        if ( null === $api ) {
            return $choices;
        }

        // Check transient cache.
        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );
        $cache_key   = 'lh_ghl_pipelines_' . md5( $location_id );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return array_merge( $choices, $cached );
        }

        $result = $api->get_pipelines();
        if ( is_wp_error( $result ) ) {
            return $choices;
        }

        $pipeline_choices = array();
        $pipelines        = $result['pipelines'] ?? array();

        foreach ( $pipelines as $pipeline ) {
            if ( ! empty( $pipeline['id'] ) ) {
                $pipeline_choices[] = array(
                    'label' => esc_html( $pipeline['name'] ?? $pipeline['id'] ),
                    'value' => $pipeline['id'],
                );
            }
        }

        // Cache for 5 minutes.
        set_transient( $cache_key, $pipeline_choices, 5 * MINUTE_IN_SECONDS );

        return array_merge( $choices, $pipeline_choices );
    }

    /**
     * Get custom field dropdown choices from the GHL API.
     *
     * Fetches location contact custom fields and formats them as choices for the
     * generic_map key column (label = field name, name = field id for API).
     * Results are cached in a transient for 5 minutes.
     *
     * @return array Array of choices (label, name) for GF generic_map key_choices.
     */
    private function get_custom_field_choices(): array {
        $choices = array();

        $api = $this->get_api();
        if ( null === $api ) {
            return $choices;
        }

        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );
        $cache_key   = 'lh_ghl_custom_fields_' . md5( $location_id );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $result = $api->get_custom_fields();
        if ( is_wp_error( $result ) ) {
            return $choices;
        }

        // API may return customFields or customField.
        $list = $result['customFields'] ?? $result['customField'] ?? array();
        if ( ! is_array( $list ) ) {
            return $choices;
        }

        foreach ( $list as $field ) {
            // Prefer key (e.g. project_description) when present so GHL merge tags match; else use id.
            $key  = $field['key'] ?? null;
            $id   = $field['id'] ?? $field['_id'] ?? null;
            $val  = $key ? (string) $key : ( $id ? (string) $id : null );
            $name = $field['name'] ?? $field['label'] ?? $field['fieldName'] ?? null;
            if ( ! empty( $val ) ) {
                $choices[] = array(
                    'label' => ! empty( $name ) ? $name : $val,
                    'name'  => $val,
                );
            }
        }

        set_transient( $cache_key, $choices, 5 * MINUTE_IN_SECONDS );

        return $choices;
    }

    /**
     * Get options for a single GHL custom field (for dropdown/select type).
     * Used to populate form field choices on the frontend.
     *
     * @param string $field_key Custom field key or id (e.g. project_description).
     * @return array List of { value, text } for Gravity Forms choices.
     */
    private function get_custom_field_options_for_frontend( string $field_key ): array {
        $api = $this->get_api();
        if ( null === $api || '' === $field_key ) {
            return array();
        }

        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );
        $cache_key   = 'lh_ghl_custom_fields_raw_' . md5( $location_id );
        $cached      = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            $list = $cached;
        } else {
            $result = $api->get_custom_fields();
            if ( is_wp_error( $result ) ) {
                return array();
            }
            $list = $result['customFields'] ?? $result['customField'] ?? array();
            if ( ! is_array( $list ) ) {
                return array();
            }
            set_transient( $cache_key, $list, 5 * MINUTE_IN_SECONDS );
        }

        $field_key = strtolower( $field_key );
        foreach ( $list as $field ) {
            $key = isset( $field['key'] ) ? strtolower( (string) $field['key'] ) : null;
            $id  = isset( $field['id'] ) ? (string) $field['id'] : null;
            if ( ( $key && $key === $field_key ) || ( $id && $id === $field_key ) ) {
                $opts = $field['options'] ?? $field['dropdownOptions'] ?? $field['values'] ?? $field['choices'] ?? null;
                if ( ! is_array( $opts ) ) {
                    return array();
                }
                $choices = array();
                foreach ( $opts as $opt ) {
                    if ( is_string( $opt ) ) {
                        $choices[] = array( 'value' => $opt, 'text' => $opt );
                    } elseif ( is_array( $opt ) ) {
                        $v = $opt['value'] ?? $opt['id'] ?? $opt['label'] ?? '';
                        $t = $opt['label'] ?? $opt['text'] ?? $opt['name'] ?? $v;
                        if ( '' !== $v || '' !== $t ) {
                            $choices[] = array( 'value' => (string) $v, 'text' => (string) $t );
                        }
                    }
                }
                return $choices;
            }
        }
        return array();
    }

    /**
     * Get user dropdown choices from the GHL API (for Assign To).
     *
     * Fetches location users and formats them as choices for the select field.
     * Results are cached in a transient for 5 minutes.
     *
     * @return array Array of choices (label, value) for the Assign To select.
     */
    private function get_user_choices(): array {
        $choices = array(
            array(
                'label' => esc_html__( '— No assignment —', 'lh-ghl-gravity-addon' ),
                'value' => '',
            ),
        );

        $api = $this->get_api();
        if ( null === $api ) {
            return $choices;
        }

        $location_id = $this->get_plugin_setting( 'lh_ghl_location_id' );
        $cache_key   = 'lh_ghl_users_' . md5( $location_id );
        $cached      = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return array_merge( $choices, $cached );
        }

        $result = $api->get_users();
        if ( is_wp_error( $result ) ) {
            return $choices;
        }

        $list = $result['users'] ?? $result['user'] ?? array();
        if ( ! is_array( $list ) ) {
            return $choices;
        }

        $user_choices = array();
        foreach ( $list as $user ) {
            $id = $user['id'] ?? $user['_id'] ?? null;
            if ( empty( $id ) ) {
                continue;
            }
            $name = $user['name'] ?? null;
            if ( empty( $name ) && ( ! empty( $user['firstName'] ) || ! empty( $user['lastName'] ) ) ) {
                $name = trim( ( $user['firstName'] ?? '' ) . ' ' . ( $user['lastName'] ?? '' ) );
            }
            if ( empty( $name ) && ! empty( $user['email'] ) ) {
                $name = $user['email'];
            }
            $user_choices[] = array(
                'label' => $name ? esc_html( $name ) : (string) $id,
                'value' => (string) $id,
            );
        }

        set_transient( $cache_key, $user_choices, 5 * MINUTE_IN_SECONDS );

        return array_merge( $choices, $user_choices );
    }

    /**
     * Get stage dropdown choices based on the currently selected pipeline.
     *
     * Reads the pipeline ID from the current feed settings and fetches
     * the corresponding stages from the API.
     *
     * @return array
     */
    private function get_stage_choices(): array {
        $choices = array(
            array(
                'label' => esc_html__( '— Select a Stage —', 'lh-ghl-gravity-addon' ),
                'value' => '',
            ),
        );

        $settings    = $this->get_current_settings();
        $pipeline_id = rgar( $settings, 'opportunityPipeline' );

        if ( empty( $pipeline_id ) ) {
            return $choices;
        }

        $api = $this->get_api();
        if ( null === $api ) {
            return $choices;
        }

        $stages = $api->get_pipeline_stages( $pipeline_id );
        if ( is_wp_error( $stages ) ) {
            return $choices;
        }

        foreach ( $stages as $stage ) {
            if ( ! empty( $stage['id'] ) ) {
                $choices[] = array(
                    'label' => esc_html( $stage['name'] ?? $stage['id'] ),
                    'value' => $stage['id'],
                );
            }
        }

        return $choices;
    }

    /**
     * Define the columns shown in the feed list table.
     *
     * @return array
     */
    public function feed_list_columns(): array {
        return array(
            'feedName'          => esc_html__( 'Name', 'lh-ghl-gravity-addon' ),
            'enableOpportunity' => esc_html__( 'Opportunity', 'lh-ghl-gravity-addon' ),
        );
    }

    /**
     * Format the Opportunity column value in the feed list.
     *
     * @param array $feed The feed data.
     *
     * @return string Formatted HTML.
     */
    public function get_column_value_enableOpportunity( $feed ): string {
        $enabled = rgars( $feed, 'meta/enableOpportunity' );

        if ( $enabled ) {
            return '<span style="color: green;">&#10003; ' . esc_html__( 'Enabled', 'lh-ghl-gravity-addon' ) . '</span>';
        }

        return '<span style="color: #999;">&#8212; ' . esc_html__( 'Disabled', 'lh-ghl-gravity-addon' ) . '</span>';
    }

    // -------------------------------------------------------------------------
    // AJAX — Fetch Pipeline Stages
    // -------------------------------------------------------------------------

    /**
     * AJAX handler — Fetch stages for a given pipeline.
     *
     * Used for dynamic stage loading when the pipeline dropdown changes.
     *
     * @return void
     */
    public function ajax_get_stages(): void {
        check_ajax_referer( 'lh_ghl_get_stages', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'lh-ghl-gravity-addon' ) ) );
        }

        $pipeline_id = sanitize_text_field( wp_unslash( $_POST['pipeline_id'] ?? '' ) );

        if ( empty( $pipeline_id ) ) {
            wp_send_json_success( array( 'stages' => array() ) );
        }

        $api = $this->get_api();
        if ( null === $api ) {
            wp_send_json_error( array( 'message' => __( 'API not configured. Please save your API Key and Location ID first.', 'lh-ghl-gravity-addon' ) ) );
        }

        $stages = $api->get_pipeline_stages( $pipeline_id );
        if ( is_wp_error( $stages ) ) {
            wp_send_json_error( array( 'message' => wp_strip_all_tags( $stages->get_error_message() ) ) );
        }

        $stage_choices = array();
        foreach ( $stages as $stage ) {
            if ( ! empty( $stage['id'] ) ) {
                $stage_choices[] = array(
                    'value' => sanitize_text_field( $stage['id'] ),
                    'label' => sanitize_text_field( $stage['name'] ?? $stage['id'] ),
                );
            }
        }

        wp_send_json_success( array( 'stages' => $stage_choices ) );
    }

    // -------------------------------------------------------------------------
    // Frontend: Dynamic choices from GHL
    // -------------------------------------------------------------------------

    /**
     * Populate form field choices from GHL when the field has a special CSS class.
     *
     * Add one of these CSS classes to a Dropdown or Radio field in the form editor:
     *   - ghl-choices-pipelines   — options from GHL pipelines
     *   - ghl-choices-users       — options from GHL location users
     *   - ghl-choices-custom-KEY  — options from GHL custom field (e.g. ghl-choices-custom-project_type)
     *
     * The value the user selects is saved with the entry. Map that form field to the
     * corresponding GHL contact/custom field in the feed so it is sent to GHL.
     *
     * @param array    $choices Existing choices.
     * @param array|object $field  The form field.
     * @param string   $value   Current value.
     * @return array
     */
    public function filter_field_choices_from_ghl( $choices, $field, $value ) {
        $type = is_array( $field ) ? rgar( $field, 'type' ) : ( $field->type ?? '' );
        if ( ! in_array( $type, array( 'select', 'multiselect', 'radio' ), true ) ) {
            return $choices;
        }

        $css = is_array( $field ) ? rgar( $field, 'cssClass' ) : ( $field->cssClass ?? '' );
        if ( empty( $css ) || strpos( $css, 'ghl-choices-' ) === false ) {
            return $choices;
        }

        if ( ! preg_match( '#ghl-choices-(pipelines|users|custom-[a-zA-Z0-9_-]+)#', $css, $m ) ) {
            return $choices;
        }
        $source = $m[1];

        $ghl_choices = $this->get_ghl_options_for_frontend( $source );
        if ( empty( $ghl_choices ) ) {
            return $choices;
        }

        return $ghl_choices;
    }

    /**
     * Get options for frontend dropdown from a GHL source.
     *
     * @param string $source One of: pipelines, users, custom-{key}.
     * @return array Gravity Forms choices (text, value).
     */
    private function get_ghl_options_for_frontend( string $source ): array {
        $out = array();
        if ( $source === 'pipelines' ) {
            $raw = $this->get_pipeline_choices();
            foreach ( $raw as $c ) {
                $label = $c['label'] ?? $c['value'] ?? '';
                $val   = $c['value'] ?? '';
                if ( '' === $val && strpos( $label, 'Select' ) !== false ) {
                    continue; // Skip "— Select a Pipeline —" on frontend if desired, or include it.
                }
                $out[] = array( 'text' => $label, 'value' => $val );
            }
        } elseif ( $source === 'users' ) {
            $raw = $this->get_user_choices();
            foreach ( $raw as $c ) {
                $label = $c['label'] ?? $c['value'] ?? '';
                $val   = $c['value'] ?? '';
                if ( '' === $val ) {
                    continue;
                }
                $out[] = array( 'text' => $label, 'value' => $val );
            }
        } elseif ( strpos( $source, 'custom-' ) === 0 ) {
            $key = substr( $source, 7 );
            $out = $this->get_custom_field_options_for_frontend( $key );
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Feed Processing — Milestone 6 + 7 (Background Dispatch)
    // -------------------------------------------------------------------------

    /**
     * Process the feed when a form is submitted.
     *
     * This is the entry point called by GFFeedAddOn after a form is submitted.
     * It performs lightweight, synchronous validation and then dispatches
     * the actual GHL sync to a background cron task so the form submission
     * is not blocked by API calls.
     *
     * Synchronous steps (fast):
     *   1. Conditional logic — handled automatically by GFFeedAddOn.
     *   2. Duplicate protection — skip if already synced.
     *   3. Email validation — fail fast if email is missing/invalid.
     *   4. Dispatch to background via LH_GHL_Background::schedule().
     *
     * @param array $feed  The current feed being processed.
     * @param array $entry The current entry.
     * @param array $form  The current form.
     *
     * @return array|null Modified entry or null.
     */
    public function process_feed( $feed, $entry, $form ): ?array {
        $logger     = $this->get_logger();
        $entry_id   = (int) rgar( $entry, 'id' );
        $form_id    = (int) rgar( $form, 'id' );
        $feed_name  = rgars( $feed, 'meta/feedName' );
        $feed_id    = (int) rgar( $feed, 'id' );
        $start_time = microtime( true );

        // --- Log processing start ---
        if ( $logger ) {
            $logger->log_processing_start( $entry_id, $form_id, $feed_name );
        }

        // ------------------------------------------------------------------
        // Conditional logic is handled automatically by GFFeedAddOn before
        // process_feed() is called. No manual check needed.
        // ------------------------------------------------------------------

        // ------------------------------------------------------------------
        // Quick duplicate check — avoid scheduling unnecessary work.
        // ------------------------------------------------------------------
        if ( lh_ghl_is_entry_synced( $entry_id ) ) {
            if ( $logger ) {
                $logger->info( sprintf( 'Entry #%d already synced to GHL — skipping.', $entry_id ) );
                $logger->log_processing_end( $entry_id, 'skipped', microtime( true ) - $start_time );
            }
            return $entry;
        }

        // ------------------------------------------------------------------
        // Quick email validation — fail fast before dispatching.
        // ------------------------------------------------------------------
        $raw_email = $this->get_contact_field_value_from_map( $feed, $form, $entry, 'email' );
        $email     = lh_ghl_validate_email( $raw_email );

        if ( false === $email ) {
            if ( $logger ) {
                $logger->log_validation_error( 'email', 'Email is missing or invalid — aborting feed processing.', array(
                    'entry_id'  => $entry_id,
                    'form_id'   => $form_id,
                    'raw_value' => sanitize_text_field( $raw_email ),
                ) );
                $logger->log_processing_end( $entry_id, 'failed', microtime( true ) - $start_time );
            }
            return $entry;
        }

        // ------------------------------------------------------------------
        // Dispatch to background processing.
        // The actual API calls (search, create/update contact, create
        // opportunity) run asynchronously via wp_schedule_single_event().
        // schedule() also spawns a non-blocking wp-cron request so the sync
        // runs shortly after submit without a manual wp-cron hit.
        // ------------------------------------------------------------------
        LH_GHL_Background::schedule( $entry_id, $feed_id );

        if ( $logger ) {
            $logger->log_processing_end( $entry_id, 'dispatched', microtime( true ) - $start_time );
        }

        return $entry;
    }

    /**
     * Execute the GHL sync for a single feed/entry.
     *
     * Called by LH_GHL_Background::process() from a WordPress cron context.
     * Performs the actual API work:
     *   - HTTP Call 1 of 3: Search contact by email
     *   - HTTP Call 2 of 3: Create or update contact
     *   - HTTP Call 3 of 3: Create opportunity (optional, if enabled)
     *
     * This design guarantees max 3 HTTP calls per submission, as required
     * by the performance spec.
     *
     * @param array $feed  The feed configuration.
     * @param array $entry The form entry.
     * @param array $form  The form object.
     *
     * @return void
     */
    public function execute_sync( array $feed, array $entry, array $form ): void {
        $logger     = $this->get_logger();
        $entry_id   = (int) rgar( $entry, 'id' );
        $form_id    = (int) rgar( $form, 'id' );
        $feed_name  = rgars( $feed, 'meta/feedName' );
        $start_time = microtime( true );

        if ( $logger ) {
            $logger->info(
                sprintf( '--- Sync Execute Start --- Entry #%d | Form #%d | Feed: %s', $entry_id, $form_id, $feed_name )
            );
        }

        // ------------------------------------------------------------------
        // Re-check duplicate protection (race-condition safety).
        // Another process may have synced this entry between dispatch
        // and execution.
        // ------------------------------------------------------------------
        if ( lh_ghl_is_entry_synced( $entry_id ) ) {
            if ( $logger ) {
                $logger->info( sprintf( 'Entry #%d already synced — skipping (detected during sync execution).', $entry_id ) );
            }
            return;
        }

        // ------------------------------------------------------------------
        // Validate email from mapped fields (form field or custom value).
        // ------------------------------------------------------------------
        $raw_email = $this->get_contact_field_value_from_map( $feed, $form, $entry, 'email' );
        $email     = lh_ghl_validate_email( $raw_email );

        if ( false === $email ) {
            if ( $logger ) {
                $logger->log_validation_error( 'email', 'Email is missing or invalid during sync execution.', array(
                    'entry_id' => $entry_id,
                    'form_id'  => $form_id,
                ) );
            }
            return;
        }

        // ------------------------------------------------------------------
        // Get the API client.
        // ------------------------------------------------------------------
        $api = $this->get_api();

        if ( null === $api ) {
            if ( $logger ) {
                $logger->error( 'API client not available — API Key or Location ID is missing. Aborting sync.' );
            }
            return;
        }

        // ------------------------------------------------------------------
        // Build contact data payload from mapped fields.
        // ------------------------------------------------------------------
        $contact_data = $this->build_contact_data( $feed, $entry, $form );

        // ------------------------------------------------------------------
        // HTTP Call 1 of 3 — Search contact by email.
        // ------------------------------------------------------------------
        if ( $logger ) {
            $logger->info( sprintf( 'Searching for existing contact with email: %s', $email ) );
        }

        $search_result = $api->search_contact( $email );

        if ( is_wp_error( $search_result ) ) {
            if ( $logger ) {
                $logger->log_failure( 'search_contact', 'Failed to search for contact by email.', $search_result );
            }
            return;
        }

        // ------------------------------------------------------------------
        // HTTP Call 2 of 3 — Create or update contact.
        // ------------------------------------------------------------------
        $existing_contact_id = ! empty( $search_result['contact']['id'] )
            ? $search_result['contact']['id']
            : null;

        if ( $existing_contact_id ) {
            // --- Update existing contact ---
            if ( $logger ) {
                $logger->info( sprintf( 'Existing contact found (ID: %s) — updating.', $existing_contact_id ) );
            }

            $contact_result = $api->update_contact( $existing_contact_id, $contact_data );
            $contact_action = 'updated';
        } else {
            // --- Create new contact ---
            if ( $logger ) {
                $logger->info( 'No existing contact found — creating new contact.' );
            }

            $contact_result = $api->create_contact( $contact_data );
            $contact_action = 'created';
        }

        if ( is_wp_error( $contact_result ) ) {
            if ( $logger ) {
                $logger->log_failure(
                    'created' === $contact_action ? 'create_contact' : 'update_contact',
                    sprintf( 'Failed to %s contact for email %s.', $contact_action, $email ),
                    $contact_result
                );
            }
            return;
        }

        // Extract the contact ID from the API response.
        $contact_id = $contact_result['contact']['id'] ?? $existing_contact_id ?? '';

        if ( $logger ) {
            $logger->info( sprintf( 'Contact %s successfully (ID: %s).', $contact_action, $contact_id ) );
        }

        // Store the GHL contact ID as entry meta for reference.
        if ( ! empty( $contact_id ) ) {
            gform_update_meta( $entry_id, 'lh_ghl_contact_id', $contact_id );
        }

        // ------------------------------------------------------------------
        // HTTP Call 3 of 3 (optional) — Create opportunity.
        // ------------------------------------------------------------------
        $enable_opportunity = rgars( $feed, 'meta/enableOpportunity' );

        if ( $enable_opportunity && ! empty( $contact_id ) ) {
            $opportunity_data = $this->build_opportunity_data( $feed, $entry, $form, $contact_id );

            // Verify required opportunity fields are present.
            if ( ! empty( $opportunity_data['pipelineId'] ) && ! empty( $opportunity_data['pipelineStageId'] ) ) {
                if ( $logger ) {
                    $logger->info( sprintf(
                        'Creating opportunity: "%s" in pipeline %s / stage %s.',
                        $opportunity_data['name'] ?? 'unknown',
                        $opportunity_data['pipelineId'],
                        $opportunity_data['pipelineStageId']
                    ) );
                }

                $opp_result = $api->create_opportunity( $opportunity_data );

                if ( is_wp_error( $opp_result ) ) {
                    if ( $logger ) {
                        $logger->log_failure( 'create_opportunity', 'Failed to create opportunity.', $opp_result );
                    }
                    // Note: Opportunity failure is non-fatal. The contact was already
                    // synced successfully, so we continue and mark as synced.
                } else {
                    $opp_id = $opp_result['opportunity']['id'] ?? 'unknown';

                    if ( $logger ) {
                        $logger->info( sprintf( 'Opportunity created successfully (ID: %s).', $opp_id ) );
                    }

                    // Store the GHL opportunity ID as entry meta for reference.
                    if ( 'unknown' !== $opp_id ) {
                        gform_update_meta( $entry_id, 'lh_ghl_opportunity_id', $opp_id );
                    }
                }
            } else {
                if ( $logger ) {
                    $logger->warning( 'Opportunity creation enabled but Pipeline or Stage is not configured — skipping opportunity.' );
                }
            }
        }

        // ------------------------------------------------------------------
        // Mark entry as synced.
        // ------------------------------------------------------------------
        lh_ghl_mark_entry_synced( $entry_id );

        if ( $logger ) {
            $logger->info( sprintf( 'Entry #%d marked as synced to GHL.', $entry_id ) );
        }

        // ------------------------------------------------------------------
        // Log full result.
        // ------------------------------------------------------------------
        if ( $logger ) {
            $logger->log_processing_end( $entry_id, 'success', microtime( true ) - $start_time );
        }
    }

    /**
     * Get the resolved value for a single contact field from the feed's contact field map.
     *
     * Uses GF's get_generic_map_fields when contactFieldMap is in generic_map format;
     * falls back to legacy contactFieldMap_* meta keys.
     *
     * @param array  $feed    The feed.
     * @param array  $form    The form.
     * @param array  $entry   The entry.
     * @param string $ghl_key GHL contact field key (e.g. email, firstName).
     * @return string Resolved value or empty string.
     */
    private function get_contact_field_value_from_map( array $feed, array $form, array $entry, string $ghl_key ): string {
        $contact_fields = $this->get_generic_map_fields( $feed, 'contactFieldMap', $form, $entry );
        if ( ! empty( $contact_fields ) && array_key_exists( $ghl_key, $contact_fields ) ) {
            return lh_ghl_sanitize_field( (string) $contact_fields[ $ghl_key ] );
        }
        // Legacy: contactFieldMap_firstName etc.
        $field_id = rgars( $feed, 'meta/contactFieldMap_' . $ghl_key );
        if ( empty( $field_id ) ) {
            return '';
        }
        $value = $this->get_field_value( $form, $entry, $field_id );
        return lh_ghl_sanitize_field( (string) $value );
    }

    /**
     * Canonical label → GHL API key map for standard contact fields.
     *
     * LeadConnector API expects camelCase; schema or UI may use human-readable labels.
     *
     * @var array<string, string>
     */
    private static $contact_label_to_api_keys = array(
        'First Name'      => 'firstName',
        'Last Name'       => 'lastName',
        'Full Name'       => 'name',
        'Email'           => 'email',
        'Phone'           => 'phone',
        'Address (Street)' => 'address1',
        'City'            => 'city',
        'State'           => 'state',
        'Postal / ZIP Code' => 'postalCode',
        'Country'         => 'country',
        'Company Name'    => 'companyName',
        'Website'         => 'website',
        'Date of Birth'   => 'dateOfBirth',
        'Lead Source'     => 'source',
    );

    /**
     * Map from stored/label contact field keys to GHL API property names (camelCase).
     *
     * The LeadConnector API expects camelCase (e.g. firstName, lastName). The feed
     * or schema may store keys as labels (e.g. "First Name", "Last Name"). This map
     * ensures we always send the correct API keys.
     *
     * @return array Map of stored_key_or_label => api_key (e.g. 'First Name' => 'firstName').
     */
    private function get_contact_field_key_to_api_map(): array {
        $map  = array_merge( array(), self::$contact_label_to_api_keys );
        $defs = $this->get_contact_field_map();
        foreach ( $defs as $field_def ) {
            $name    = $field_def['name'];
            $api_key = self::$contact_label_to_api_keys[ $name ] ?? $name;
            $map[ $name ] = $api_key;
            if ( ! empty( $field_def['label'] ) && $field_def['label'] !== $name ) {
                $map[ $field_def['label'] ] = $api_key;
            }
        }
        return $map;
    }

    /**
     * Build the contact data payload from the feed's field mappings.
     *
     * Reads the contactFieldMap (generic_map: form field or custom value per GHL field),
     * customFieldMap, tags, and the default lead source from plugin settings.
     * Normalizes contact field keys to GHL API property names (camelCase).
     *
     * @param array $feed  The current feed.
     * @param array $entry The current entry.
     * @param array $form  The current form.
     *
     * @return array Contact data ready for the API.
     */
    private function build_contact_data( array $feed, array $entry, array $form ): array {
        $data = array();

        // ----- Standard contact fields from contactFieldMap (generic_map or legacy) -----
        $contact_fields = $this->get_generic_map_fields( $feed, 'contactFieldMap', $form, $entry );
        if ( ! empty( $contact_fields ) ) {
            $key_to_api = $this->get_contact_field_key_to_api_map();
            foreach ( $contact_fields as $ghl_key => $field_value ) {
                $value = lh_ghl_sanitize_field( (string) $field_value );
                if ( '' !== $value ) {
                    // Send only API property names (camelCase); GHL rejects labels like "First Name".
                    $api_key = $key_to_api[ $ghl_key ] ?? $ghl_key;
                    $data[ $api_key ] = $value;
                }
            }
        } else {
            // Legacy: contactFieldMap_firstName etc.
            $contact_field_map = $this->get_contact_field_map();
            foreach ( $contact_field_map as $field_def ) {
                $ghl_key  = $field_def['name'];
                $meta_key = 'contactFieldMap_' . $ghl_key;
                $field_id = rgars( $feed, 'meta/' . $meta_key );
                if ( ! empty( $field_id ) ) {
                    $value = $this->get_field_value( $form, $entry, $field_id );
                    $value = lh_ghl_sanitize_field( $value );
                    if ( '' !== $value ) {
                        $data[ $ghl_key ] = $value;
                    }
                }
            }
        }

        // // ----- GHL merge-tag compatibility: send both companyName and company_name -----
        // if ( ! empty( $data['companyName'] ) ) {
        //     $data['company_name'] = $data['companyName'];
        // }
        // if ( ! empty( $data['company_name'] ) && empty( $data['companyName'] ) ) {
        //     $data['companyName'] = $data['company_name'];
        // }

        // ----- Custom fields from generic_map (use GF helper for correct format) -----
        $custom_field_map = $this->get_generic_map_fields( $feed, 'customFieldMap', $form, $entry );
        if ( ! empty( $custom_field_map ) ) {
            $ghl_custom_fields = array();
            foreach ( $custom_field_map as $cf_key => $cf_value ) {
                $cf_value = lh_ghl_sanitize_field( (string) $cf_value );
                if ( '' !== $cf_value ) {
                    $ghl_custom_fields[] = array(
                        'id'    => sanitize_text_field( (string) $cf_key ),
                        'value' => $cf_value,
                    );
                }
            }
            if ( ! empty( $ghl_custom_fields ) ) {
                $data['customFields'] = $ghl_custom_fields;
            }
        }

        // ----- Tags -----
        $tags = rgars( $feed, 'meta/contactTags' );

        if ( ! empty( $tags ) ) {
            // Resolve merge tags.
            $tags = GFCommon::replace_variables( $tags, $form, $entry, false, false, false, 'text' );
            $tags = sanitize_text_field( $tags );

            if ( ! empty( $tags ) ) {
                $tag_array = array_map( 'trim', explode( ',', $tags ) );
                $tag_array = array_filter( $tag_array ); // Remove empty strings.

                if ( ! empty( $tag_array ) ) {
                    $data['tags'] = array_values( $tag_array );
                }
            }
        }

        // ----- Default lead source from plugin settings (only if not mapped) -----
        if ( empty( $data['source'] ) ) {
            $lead_source = $this->get_plugin_setting( 'lh_ghl_default_lead_source' );
            if ( ! empty( $lead_source ) ) {
                $data['source'] = sanitize_text_field( $lead_source );
            }
        }

        return $data;
    }

    /**
     * Build the opportunity data payload from the feed settings.
     *
     * Reads pipeline, stage, name (merge tags), monetary value,
     * assign-to, and status from the feed meta.
     *
     * @param array  $feed       The current feed.
     * @param array  $entry      The current entry.
     * @param array  $form       The current form.
     * @param string $contact_id The GHL contact ID to attach the opportunity to.
     *
     * @return array Opportunity data ready for the API.
     */
    private function build_opportunity_data( array $feed, array $entry, array $form, string $contact_id ): array {
        $data = array(
            'contactId'       => $contact_id,
            'pipelineId'      => sanitize_text_field( rgars( $feed, 'meta/opportunityPipeline' ) ),
            'pipelineStageId' => sanitize_text_field( rgars( $feed, 'meta/opportunityStage' ) ),
            'status'          => sanitize_text_field( rgars( $feed, 'meta/opportunityStatus' ) ?: 'open' ),
        );

        // Opportunity name — supports merge tags.
        $opp_name = rgars( $feed, 'meta/opportunityName' );

        if ( ! empty( $opp_name ) ) {
            $opp_name      = GFCommon::replace_variables( $opp_name, $form, $entry, false, false, false, 'text' );
            $data['name']  = sanitize_text_field( $opp_name );
        } else {
            // Fallback name when not configured.
            $data['name'] = sprintf( 'Form Submission #%d', (int) rgar( $entry, 'id' ) );
        }

        // Monetary value — supports merge tags.
        $monetary_value = rgars( $feed, 'meta/opportunityValue' );

        if ( ! empty( $monetary_value ) ) {
            $monetary_value = GFCommon::replace_variables( $monetary_value, $form, $entry, false, false, false, 'text' );
            $monetary_value = sanitize_text_field( $monetary_value );

            // Strip non-numeric characters (except decimal point) and convert to float.
            $numeric = (float) preg_replace( '/[^0-9.]/', '', $monetary_value );

            if ( $numeric > 0 ) {
                $data['monetaryValue'] = $numeric;
            }
        }

        // Assign to — optional GHL user ID.
        $assign_to = rgars( $feed, 'meta/opportunityAssignTo' );

        if ( ! empty( $assign_to ) ) {
            $data['assignedTo'] = sanitize_text_field( $assign_to );
        }

        // Lead source from plugin settings.
        $lead_source = $this->get_plugin_setting( 'lh_ghl_default_lead_source' );

        if ( ! empty( $lead_source ) ) {
            $data['source'] = sanitize_text_field( $lead_source );
        }

        return $data;
    }

    /**
     * Determine if the feed can be duplicated.
     *
     * @param int $feed_id The feed ID.
     *
     * @return bool
     */
    public function can_duplicate_feed( $feed_id ): bool {
        return true;
    }
}
