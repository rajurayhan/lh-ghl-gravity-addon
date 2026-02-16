=== GoHighLevel Gravity Add-On ===
Contributors: rakaaitechbd
Donate link: https://rakaaitech.com
Tags: gravity forms, integration, crm, contact form, lead connector
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync Gravity Forms submissions to GoHighLevel (LeadConnector API): create or update contacts and optionally create opportunities in your pipeline.

== Description ==

This add-on connects Gravity Forms to GoHighLevel (LeadConnector API). When a form is submitted, it can create or update a contact in your GoHighLevel location and optionally create an opportunity in a pipeline of your choice. Field mapping, conditional logic, duplicate protection, and background processing are supported.

**Requirements:**
* WordPress 6.0 or higher
* PHP 8.1 or higher
* Gravity Forms 2.5 or higher (license required)
* GoHighLevel account with API key and Location ID

**Features:**
* Contact sync — create or update contacts by email (duplicate-safe)
* Optional opportunity creation with pipeline, stage, value, and assignee
* Map form fields to contact properties (name, email, phone, custom fields, tags)
* Conditional logic so feeds run only when conditions are met
* Background processing so form submission is not blocked
* Test connection to validate API key and Location ID
* Structured logging (optional debug mode) for troubleshooting

By installing and configuring this plugin with your GoHighLevel API credentials, you consent to sending form submission data to GoHighLevel's servers in accordance with [GoHighLevel's terms and policies](https://www.gohighlevel.com/terms-of-service).

== Installation ==

1. Install and activate Gravity Forms.
2. Install this plugin: upload the plugin folder to `wp-content/plugins/` or install via WordPress admin (Plugins → Add New → Upload), then activate.
3. Go to **Forms → Settings → GoHighLevel** and enter your API Key and Location ID. Save and use **Test Connection** to verify.
4. Edit a form, open **Settings → GoHighLevel**, add a feed, map at least the Email field, and optionally enable opportunity creation and conditional logic.

For API credentials: in GoHighLevel, go to Settings → API Keys and create or copy an API key; find your Location ID in Settings → Business Info or in the location URL.

== Screenshots ==

1. Forms → Settings → GoHighLevel: API key, Location ID, and Test Connection.
2. Feed settings: contact field mapping, custom fields, and optional opportunity creation.

== Frequently Asked Questions ==

= Where do I get my GoHighLevel API key and Location ID? =

Log in to GoHighLevel (app.gohighlevel.com or your white-label URL). Go to Settings → API Keys to create or copy an API key. Find your Location ID under Settings → Business Info or in the URL when viewing your location (e.g. `loc_xxxx`).

= Submissions are not syncing. What should I check? =

Enable **Forms → Settings → Logging**, then enable **GoHighLevel** debug logging under Forms → Settings → GoHighLevel. Submit a test form and open the add-on log under Forms → Settings → Logging to see validation errors or API errors. Ensure your host runs wp-cron so background sync can run.

= Can I map custom fields from GoHighLevel? =

Yes. In the feed settings, use the Custom Fields section; options are loaded from your GoHighLevel location.

== Changelog ==

= 1.0.0 =
* Initial release.
* Contact create/update, optional opportunity creation, field mapping, conditional logic, duplicate protection, background processing, and logging.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Requires WordPress 6.0+, PHP 8.1+, and Gravity Forms 2.5+.
