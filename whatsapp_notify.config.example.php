<?php
/**
 * Example configuration for includes/hooks/whatsapp_notify.php
 *
 * 1. Copy this file to a location OUTSIDE your web root and OUTSIDE
 *    includes/hooks/ (WHMCS loads every .php file in that folder as a hook),
 *    for example one level above the WHMCS directory:
 *        /home/youruser/whatsapp_notify.config.php
 * 2. Fill in your values and set permissions to 0600 (or 0640 with the
 *    web server group).
 * 3. If you store it somewhere else, set the WA_CONFIG_FILE environment
 *    variable (e.g. in your PHP-FPM pool) to the full path.
 *
 * Environment variables WA_TOKEN and WA_PHONE_ID, if set, override the
 * token and phone_number_id values below.
 */

return [
    // Permanent System User access token from Meta Business Settings.
    // Do NOT use the 24-hour temporary token from the API Setup page.
    'token' => '',

    // Phone Number ID from WhatsApp > API Setup (not the phone number itself).
    'phone_number_id' => '',

    // Graph API version. Check Meta's changelog and bump when needed.
    'graph_version' => 'v20.0',

    // Language code your templates were approved in (e.g. en, en_US, hi).
    'language' => 'en',

    // Name of the client custom field (tick box) used for opt-in.
    // Set to null to disable the opt-in check (not recommended).
    'optin_field' => 'WhatsApp notifications',

    // Default country calling code (digits only) added when a client's
    // phone number has no country code. Leave empty to skip such numbers.
    'default_country_code' => '91',

    // HTTP timeout in seconds. Keep this low so WHMCS is never blocked.
    'timeout' => 10,

    // Template names per event. Set an event to null to disable it.
    'templates' => [
        'invoice_created'  => 'invoice_created',  // {{1}} name, {{2}} invoice no, {{3}} amount, {{4}} due date, {{5}} link
        'payment_received' => 'payment_received', // {{1}} name, {{2}} invoice no, {{3}} amount
        'ticket_reply'     => 'ticket_reply',     // {{1}} name, {{2}} ticket id, {{3}} ticket subject
    ],
];
