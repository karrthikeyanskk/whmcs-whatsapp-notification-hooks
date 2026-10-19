<?php
/**
 * WHMCS WhatsApp Notification Hooks
 *
 * Sends WhatsApp Cloud API *template* messages for three WHMCS events:
 *   - InvoiceCreated   (invoice released to the client; drafts are skipped)
 *   - InvoicePaid      (payment received)
 *   - TicketAdminReply (staff replied to a support ticket)
 *
 * Install: copy this file to <whmcs>/includes/hooks/whatsapp_notify.php
 * Config:  see whatsapp_notify.config.example.php in the repository.
 *
 * License: MIT (c) 2026 ShoHost - https://github.com/karrthikeyanskk/whmcs-whatsapp-notification-hooks
 * Independent example code. Not affiliated with or endorsed by WHMCS or Meta.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Load configuration once per request.
 */
function wa_notify_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'token'                => '',
        'phone_number_id'      => '',
        'graph_version'        => 'v20.0',
        'language'             => 'en',
        'optin_field'          => 'WhatsApp notifications',
        'default_country_code' => '',
        'timeout'              => 10,
        'templates'            => [
            'invoice_created'  => 'invoice_created',
            'payment_received' => 'payment_received',
            'ticket_reply'     => 'ticket_reply',
        ],
    ];

    $file = getenv('WA_CONFIG_FILE');
    if (!$file && defined('ROOTDIR')) {
        // Default: one level above the WHMCS directory (outside the web root
        // on most setups).
        $file = dirname(ROOTDIR) . '/whatsapp_notify.config.php';
    }

    $loaded = [];
    if ($file && is_readable($file)) {
        $loaded = include $file;
        if (!is_array($loaded)) {
            $loaded = [];
        }
    }

    $config = array_replace($defaults, $loaded);
    $config['templates'] = array_replace($defaults['templates'], $loaded['templates'] ?? []);

    // Environment variables win over the file.
    if (getenv('WA_TOKEN')) {
        $config['token'] = getenv('WA_TOKEN');
    }
    if (getenv('WA_PHONE_ID')) {
        $config['phone_number_id'] = getenv('WA_PHONE_ID');
    }

    return $config;
}

/**
 * GetClientsDetails returns fields both at the top level and under "client"
 * depending on version; normalise to one flat array.
 */
function wa_notify_client(int $clientId): ?array
{
    if ($clientId <= 0) {
        return null;
    }
    $result = localAPI('GetClientsDetails', ['clientid' => $clientId]);
    if (($result['result'] ?? '') !== 'success') {
        return null;
    }
    return isset($result['client']) && is_array($result['client'])
        ? array_merge($result, $result['client'])
        : $result;
}

/**
 * Return the client's number in international format without "+",
 * e.g. 919876543210, or null if it cannot be used.
 */
function wa_notify_phone(array $client): ?string
{
    $config = wa_notify_config();

    // With phone number formatting enabled WHMCS stores "+91.9876543210".
    $formatted = (string) ($client['phonenumberformatted'] ?? '');
    if ($formatted !== '' && strpos($formatted, '+') === 0) {
        $digits = preg_replace('/\D+/', '', $formatted);
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }

    $digits = preg_replace('/\D+/', '', (string) ($client['phonenumber'] ?? ''));
    if ($digits === '') {
        return null;
    }
    $digits = ltrim($digits, '0');

    $cc = preg_replace('/\D+/', '', (string) ($client['phonecc'] ?? $config['default_country_code']));
    if ($cc !== '' && strpos($digits, $cc) !== 0) {
        $digits = $cc . $digits;
    }

    return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
}

/**
 * Opt-in check against a tick-box client custom field.
 */
function wa_notify_opted_in(int $clientId): bool
{
    $fieldName = wa_notify_config()['optin_field'];
    if ($fieldName === null || $fieldName === '') {
        return true; // opt-in check disabled in config
    }

    $fieldId = Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->where('fieldname', $fieldName)
        ->value('id');
    if (!$fieldId) {
        return false;
    }

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $fieldId)
        ->where('relid', $clientId)
        ->value('value');

    return $value === 'on';
}

/**
 * Prevent duplicate sends when an event is re-triggered.
 * Uses a small table that is created on first use.
 */
function wa_notify_already_sent(string $event, string $ref): bool
{
    $schema = Capsule::schema();
    if (!$schema->hasTable('mod_wa_notify_sent')) {
        $schema->create('mod_wa_notify_sent', function ($table) {
            $table->increments('id');
            $table->string('event', 32);
            $table->string('ref', 64);
            $table->timestamp('sent_at')->useCurrent();
            $table->unique(['event', 'ref']);
        });
    }

    return Capsule::table('mod_wa_notify_sent')
        ->where('event', $event)
        ->where('ref', $ref)
        ->exists();
}

function wa_notify_mark_sent(string $event, string $ref): void
{
    try {
        Capsule::table('mod_wa_notify_sent')->insert([
            'event'   => $event,
            'ref'     => $ref,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {
        // Unique key hit: already recorded.
    }
}

/**
 * Send one template message through the WhatsApp Cloud API.
 */
function wa_notify_send(string $to, string $template, array $params): bool
{
    $config = wa_notify_config();
    if ($config['token'] === '' || $config['phone_number_id'] === '') {
        logActivity('WhatsApp hooks: token or phone number ID missing, message not sent');
        return false;
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $template,
            'language'   => ['code' => $config['language']],
            'components' => [[
                'type'       => 'body',
                'parameters' => array_map(function ($value) {
                    // Template parameters may not contain new lines or tabs.
                    $text = trim(preg_replace('/\s+/', ' ', (string) $value));
                    return ['type' => 'text', 'text' => $text !== '' ? $text : '-'];
                }, array_values($params)),
            ]],
        ],
    ];

    $url = sprintf(
        'https://graph.facebook.com/%s/%s/messages',
        rawurlencode($config['graph_version']),
        rawurlencode($config['phone_number_id'])
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => (int) $config['timeout'],
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $config['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Visible under Utilities > Logs > Module Log when module logging is on.
    // The token is passed as a replace value so it never appears in the log.
    logModuleCall(
        'whatsapp_hooks',
        $template,
        $payload,
        $response !== false ? $response : $error,
        null,
        [$config['token']]
    );

    return $status >= 200 && $status < 300;
}

/**
 * Format an invoice amount in the client's currency.
 */
function wa_notify_amount($amount, array $client): string
{
    if (function_exists('formatCurrency')) {
        return (string) formatCurrency((float) $amount, (int) ($client['currency'] ?? 0));
    }
    return number_format((float) $amount, 2);
}

function wa_notify_system_url(): string
{
    return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
}

/* ------------------------------------------------------------------ */
/* Hooks                                                              */
/* ------------------------------------------------------------------ */

// InvoiceCreated fires when an invoice leaves Draft and is visible to the
// client (after the "Invoice Created" email). InvoiceCreation fires earlier,
// while line items can still change, so it is not used here.
add_hook('InvoiceCreated', 1, function ($vars) {
    try {
        $template = wa_notify_config()['templates']['invoice_created'];
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if (!$template || $invoiceId <= 0) {
            return;
        }

        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($invoice['result'] ?? '') !== 'success'
            || ($invoice['status'] ?? '') !== 'Unpaid'
            || (float) $invoice['total'] <= 0
        ) {
            return;
        }

        $clientId = (int) $invoice['userid'];
        if (!wa_notify_opted_in($clientId) || wa_notify_already_sent('invoice_created', (string) $invoiceId)) {
            return;
        }

        $client = wa_notify_client($clientId);
        $phone  = $client ? wa_notify_phone($client) : null;
        if (!$phone) {
            return;
        }

        $sent = wa_notify_send($phone, $template, [
            $client['firstname'],
            $invoice['invoicenum'] ?: $invoiceId,
            wa_notify_amount($invoice['total'], $client),
            fromMySQLDate($invoice['duedate']),
            wa_notify_system_url() . '/viewinvoice.php?id=' . $invoiceId,
        ]);
        if ($sent) {
            wa_notify_mark_sent('invoice_created', (string) $invoiceId);
        }
    } catch (\Throwable $e) {
        logActivity('WhatsApp hooks (InvoiceCreated): ' . $e->getMessage());
    }
});

add_hook('InvoicePaid', 1, function ($vars) {
    try {
        $template = wa_notify_config()['templates']['payment_received'];
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if (!$template || $invoiceId <= 0) {
            return;
        }

        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if (($invoice['result'] ?? '') !== 'success' || (float) $invoice['total'] <= 0) {
            return;
        }

        $clientId = (int) $invoice['userid'];
        if (!wa_notify_opted_in($clientId) || wa_notify_already_sent('payment_received', (string) $invoiceId)) {
            return;
        }

        $client = wa_notify_client($clientId);
        $phone  = $client ? wa_notify_phone($client) : null;
        if (!$phone) {
            return;
        }

        $sent = wa_notify_send($phone, $template, [
            $client['firstname'],
            $invoice['invoicenum'] ?: $invoiceId,
            wa_notify_amount($invoice['total'], $client),
        ]);
        if ($sent) {
            wa_notify_mark_sent('payment_received', (string) $invoiceId);
        }
    } catch (\Throwable $e) {
        logActivity('WhatsApp hooks (InvoicePaid): ' . $e->getMessage());
    }
});

add_hook('TicketAdminReply', 1, function ($vars) {
    try {
        $template = wa_notify_config()['templates']['ticket_reply'];
        $ticketId = (int) ($vars['ticketid'] ?? 0);
        if (!$template || $ticketId <= 0) {
            return;
        }

        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first();
        if (!$ticket || !(int) $ticket->userid) {
            return; // guest tickets have no client record
        }

        $clientId = (int) $ticket->userid;
        if (!wa_notify_opted_in($clientId)) {
            return;
        }

        $client = wa_notify_client($clientId);
        $phone  = $client ? wa_notify_phone($client) : null;
        if (!$phone) {
            return;
        }

        // Every staff reply is a new event, so no duplicate check here.
        wa_notify_send($phone, $template, [
            $client['firstname'],
            $ticket->tid,
            mb_strimwidth((string) $ticket->title, 0, 60, '...'),
        ]);
    } catch (\Throwable $e) {
        logActivity('WhatsApp hooks (TicketAdminReply): ' . $e->getMessage());
    }
});
