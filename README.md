# WHMCS WhatsApp Notification Hooks

Free, open-source WHMCS hook file that sends **WhatsApp Cloud API template messages** to your clients when:

| WHMCS hook | Message | Template variables |
|---|---|---|
| `InvoiceCreated` | New invoice is ready (drafts and zero-total invoices are skipped) | name, invoice number, amount, due date, invoice link |
| `InvoicePaid` | Payment received | name, invoice number, amount |
| `TicketAdminReply` | Staff replied to a support ticket | name, ticket ID, ticket subject |

It is a single PHP file you drop into `includes/hooks/`. No addon, no database migration, no external service other than Meta's WhatsApp Business Platform.

**Want ready-made WhatsApp notifications for WHMCS?** See the [ShoHost WHMCS WhatsApp Module](https://shohost.com/whmcs-whatsapp-module) for setup details, features and pricing.

## Features

- Uses approved **template messages**, so notifications work outside the 24-hour customer service window
- **Opt-in check** through a tick-box client custom field (clients who did not opt in get email only)
- **Duplicate protection** for invoice messages (small `mod_wa_notify_sent` table created on first use)
- Phone numbers normalised to international format from WHMCS client data
- Short HTTP timeouts and `try/catch` around every hook, so a slow or failing API never breaks checkout or the admin area
- Request/response logging to **Utilities > Logs > Module Log** (token is masked)
- Credentials kept **outside the web root** (config file or environment variables), never in the hook file

## Requirements

- WHMCS 8.x (uses `localAPI`, Capsule and standard hook points)
- PHP 7.4 or newer with cURL
- A Meta Business account with a WhatsApp Business Platform (Cloud API) app and a registered phone number
- A **permanent System User access token** (the 24-hour test token will expire) and your **Phone Number ID**

## Installation

1. **Copy the hook file**

   ```
   includes/hooks/whatsapp_notify.php  ->  <whmcs>/includes/hooks/whatsapp_notify.php
   ```

2. **Create the config file outside the web root**

   Copy `whatsapp_notify.config.example.php` to the folder **above** your WHMCS directory and rename it:

   ```bash
   cp whatsapp_notify.config.example.php /home/youruser/whatsapp_notify.config.php
   chmod 600 /home/youruser/whatsapp_notify.config.php
   ```

   By default the hook looks for `whatsapp_notify.config.php` one level above the WHMCS root. To use another path, set `WA_CONFIG_FILE` in your PHP-FPM pool:

   ```ini
   env[WA_CONFIG_FILE] = /home/youruser/private/whatsapp_notify.config.php
   ```

   Alternatively set `WA_TOKEN` and `WA_PHONE_ID` as environment variables; they override the file.

   Do **not** put the config file inside `includes/hooks/`. WHMCS loads every `.php` file in that folder.

3. **Create the opt-in field**

   In WHMCS go to **System Settings > Custom Client Fields**, add a **Tick Box** field named exactly `WhatsApp notifications` (or change `optin_field` in the config), and enable "Show on Order Form".

4. **Enable module logging while testing**

   **Utilities > Logs > Module Log > Enable Debug Logging**. Turn it off again when you go live.

## WhatsApp template setup

Create these as **Utility** templates in WhatsApp Manager and wait for approval. The variable order must match exactly.

`invoice_created`
```
Hi {{1}}, invoice #{{2}} for {{3}} has been generated and is due on {{4}}. You can view and pay it here: {{5}}
```

`payment_received`
```
Hi {{1}}, we have received your payment for invoice #{{2}} ({{3}}). Thank you!
```

`ticket_reply`
```
Hi {{1}}, our team has replied to your support ticket #{{2}} ({{3}}). Please log in to the client area to read it.
```

Notes:

- The template **language code** must match the `language` value in the config (for example `en` vs `en_US`).
- Keep utility templates factual. Promotional wording can get a template re-categorised as Marketing or rejected.
- Never send passwords or full card details over WhatsApp.

## Opt-in and policy

WhatsApp Business policy requires that customers **opt in** before you send them business-initiated messages. This code only messages clients who have ticked the opt-in field. Keep that check on: numbers that get blocked or reported lose quality rating and sending limits. Make it easy for customers to opt out (untick the field in the client area).

## Testing

1. Put your own number on a test client and tick the opt-in box.
2. Create a manual invoice in **Billing > Create Invoice** and publish it.
3. If no message arrives, check the **Module Log** entry `whatsapp_hooks` for Meta's error.

| Error | Usual cause |
|---|---|
| `(#132001) Template name does not exist in the translation` | Template name or language code does not match an approved template |
| `(#132000) Number of parameters does not match` | The `{{n}}` count in the template differs from what the hook sends |
| `(#131030) Recipient phone number not in allowed list` | You are still on the test number; add the recipient or go live |
| `(#190) Access token has expired` | Temporary token used; switch to a System User token |

## Extending

The same pattern works for other hook points, for example `AfterModuleSuspend` (suspension notice), `AfterModuleCreate` (welcome message with the login URL) or `InvoicePaymentReminder`. Add a template name to the `templates` array and a new `add_hook()` block that calls `wa_notify_send()`.

For high volume, consider writing messages to a queue table and sending them from a cron job instead of during the request.

## Prefer not to maintain code?

Need delivery tracking, OTP and bulk campaigns without maintaining code? See the [ShoHost WHMCS WhatsApp Module](https://shohost.com/whmcs-whatsapp-module).

## Disclaimer

This is independent example code provided under the MIT License, without warranty. It is **not affiliated with, endorsed by, or sponsored by WHMCS Limited or Meta Platforms, Inc.** WHMCS and WhatsApp are trademarks of their respective owners. Test on a staging install before using it in production, and follow WhatsApp Business messaging policies.

## License

[MIT](LICENSE) (c) 2026 ShoHost

Maintained by [ShoHost](https://shohost.com)
