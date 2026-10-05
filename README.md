# Squad by HabariPay for OpenCart 2.3.x

Receive payment with Squad by HabariPay from your OpenCart Store

**Version:** 1.0.10  
**Tested compatibility:** Squad by HabariPay for OpenCart 2.3.x 2.3.0.0, 2.3.0.1, 2.3.0.2

## Features

- Separate Test and Live modes with separately saved credentials.
- Hosted checkout; card details are entered on Squad rather than collected by this extension.
- NGN and USD checkout when supported by the merchant's Squad account.
- Encrypted Secret and Public Keys. Public Keys are optional for this hosted-checkout flow.
- Server verification of the payment reference, amount, currency and transaction status.
- Signed webhook validation and independent payment verification.
- A payment-attempt ledger and order locks to prevent duplicate order updates.

## Compatibility and requirements

| Component | Support and validation |
|---|---|
| OpenCart 2.3.0.0 | Fully tested and working |
| OpenCart 2.3.0.1 | Fully tested and working |
| OpenCart 2.3.0.2 | Fully tested and working |
| PHP | Tested with PHP 7.3.33; other versions need validation |
| Checkout theme | Default and Journal 3 templates tested; other themes need validation |
| Language | English (`en-gb`) files included |
| Database | MySQL/MariaDB with InnoDB and `utf8mb4` support |

This package is for OpenCart 2.3.x. Reach out for other OpenCart Version

The hosting environment needs:

- PHP extensions **cURL, OpenSSL, BCMath, mysqli and JSON**, including AES-256-GCM support in OpenSSL.
- HTTPS for Live checkout, the callback and the webhook, with the store's HTTPS URLs configured correctly.
- Outbound HTTPS access to Squad's API and a working certificate trust store.
- Database permission to create the payment ledger, plus normal read/write access to it and the store's order tables.
- Permission for the PHP worker to create and read `system/storage/squad/encryption-key.php`.
- An admin account permitted to install payment extensions and modify Squad settings.
- A Squad account with credentials for the selected environment and access to the currencies you enable.

PHP 7.3 is an upstream end-of-life branch. Compatibility with an older store does not provide PHP security updates; discuss maintained hosting or a validated upgrade with your host. See [PHP's unsupported branches](https://www.php.net/eol.php).

## Installation

Back up the store's files and database before installation or an upgrade.

1. Download the extension source or a release ZIP and extract it.
2. Copy the **contents** of `upload/` into your OpenCart installation, merging the existing folders:

   | Package folder | Destination inside the store |
   |---|---|
   | `upload/catalog/` | `catalog/` |
   | `upload/system/` | `system/` |
   | `upload/image/` | `image/` |
   | `upload/admin/` | Your actual admin folder, including any renamed admin folder |
3. Include the hidden `.htaccess` file in `system/library/squad/`. Do not copy its deny rules into the store's root `.htaccess`.
4. In OpenCart admin, open **Extensions → Extensions**, select **Payments**, and find **Squad by HabariPay**.
5. Click **Install**, then **Edit**. A fresh installation starts Disabled.
6. Configure the settings below, save, and test the checkout flow before accepting customer payments.

Do not create an extra `upload/` folder on the server. Do not replace the store's `config.php` files or import another store's database to install this module.

Manual copying is supported and avoids dependence on the built-in FTP installer. An `.ocmod.zip` release can also be installed through OpenCart's Extension Installer if that installer is configured correctly. A GitHub source ZIP should be extracted and copied manually. The module does not require an OCMOD XML patch.

Installation creates **one additional table**, `<DB_PREFIX>squad_attempt`, using your configured database prefix. Module settings use OpenCart's existing settings table. Each server creates its own encryption-key file when credentials are saved.

## Configuration

| Setting | Purpose |
|---|---|
| Status | Set Enabled and save when ready to offer Squad at checkout |
| Mode | Select Test (Sandbox) or Live |
| Test Secret Key | Complete `sandbox_sk_...` key from the Sandbox dashboard |
| Test Public Key | Optional `sandbox_pk_...` key from the Sandbox dashboard |
| Live Secret Key | Complete Secret Key from the Live dashboard |
| Live Public Key | Optional Public Key from the Live dashboard |
| Merchant ID | Optional additional check against the merchant ID in signed webhooks |
| Enabled currencies | Enable the checkout currencies supported by your Squad account |
| Paid order status | A status in OpenCart's configured Processing or Complete status groups |
| Awaiting payment status | A status outside those groups, usually Pending |
| Geo zone | Select All zones unless an address restriction is intended |
| Minimum order total | Set `0` unless a minimum is intended; measured in the store currency |
| Sort order | Position in the payment-method list |

Save Test and Live credentials separately. Changing Mode displays that mode's fields; Save updates the selected pair and preserves the other pair. Saved key fields stay blank. Leave an input blank to retain its saved key.

The currency selected by the customer must be enabled for Squad. For example, enabling only NGN hides Squad from USD checkouts. Currency selection does not convert an unsupported Squad merchant account into a supported one.

The module sends amounts in the lowest currency unit to Squad and displays payment-history amounts with two decimal places. It enforces a minimum of NGN 100, a USD range of 1–10,000, and an overall cap of 500,000,000 minor units. These are extension limits; provider and merchant-account limits may also apply. Gateway fees are borne by the merchant in the current implementation.

## Webhooks and going Live

1. Configure Test mode on a publicly reachable HTTPS installation and save its Test keys.
2. Copy the **Webhook URL displayed in Squad settings** into the corresponding Squad dashboard. The callback URL is included automatically when a payment is initiated.
3. Complete a sandbox payment. Confirm the verified payment, paid order status and expected stock update.
4. Check webhook delivery as well as the browser return. A payment completed without returning to the store must still be reconciled through its webhook.
5. Resolve any failed or ambiguous attempts before switching environments. Use a fresh checkout order for the first Live test.
6. Select Live, enter the Live keys, save, and configure the webhook in the Live dashboard.
7. Make a small authorised Live purchase and confirm the charged amount, provider record, order history and a single stock deduction before opening Live payments to customers.

The webhook route is `index.php?route=payment/squad/webhook`. The callback route is `index.php?route=payment/squad/callback`. Use the full URLs generated by your installation rather than copying another store's domain.

Live requests require both an HTTPS store URL and a trusted server HTTPS flag. If a proxy terminates TLS, hosting must configure PHP's HTTPS state securely. Client-supplied forwarding headers do not bypass this check.

## Troubleshooting

Open **System → Maintenance → Error Logs** and use the newest entry from the failing request. Depending on the theme and OpenCart configuration, the hosting PHP error log may also be needed. Share only the relevant error code and non-sensitive version information.

| Symptom or reason code | Check |
|---|---|
| `Could not load model extension/payment/squad!` | Confirm `catalog/model/extension/payment/squad.php` exists under the application's configured catalog directory, with the exact lowercase filename and permissions allowing PHP to read it |
| Squad is absent at checkout | Saved Enabled status, selected mode's configured Secret Key, checkout currency, geo zone and minimum total; recurring products are unsupported |
| `checkout_currency_disabled` | Enable the selected supported currency or select a currency already enabled |
| `credentials_missing_or_invalid` | Enter the correct complete Secret Key for the selected mode and save |
| `encryption_key_missing` or `credential_decryption_failed` | Check the original server encryption key or re-enter credentials on this server; preserve key backups |
| `key_storage_unavailable` | Check protected key-directory ownership and access for the PHP worker |
| `https_required` | Store HTTPS configuration and the trusted PHP HTTPS flag |
| `api_http_401` or `api_http_403` | The API rejected the request; investigate Live/Test credentials, merchant authorisation and hosting access controls |
| `api_unavailable` | Outbound connectivity, DNS, certificate trust and provider availability |
| `invalid_checkout_response` | Returned reference or checkout URL failed validation; report the safe code without allowing arbitrary redirect hosts |
| `payment_needs_reconciliation` | An earlier attempt for this order is unresolved; verify that attempt before starting another |
| `credential_changed` | The attempt was created with a different credential; retain the original credential for reconciliation |
| `internal_error` or `runtime_error` | Check required PHP capabilities and hosting logs without publishing secrets |

Method-discovery logs begin `SQUAD AVAILABILITY:`. Payment-flow logs begin `SQUAD:`. Ledger logs begin `SQUAD LEDGER:`. The generic checkout message alone does not identify whether a request failed before contacting Squad or was rejected by its API.

After changing settings, reload checkout. If stale files or checkout data persist, refresh OpenCart Modifications and Journal caches through their admin controls. Do not delete configuration files, the encryption key or payment records to clear a cache.

## Security and payment recovery

Credentials are encrypted with AES-256-GCM. The encryption key is stored separately from the database at `system/storage/squad/encryption-key.php`. Back it up securely alongside the database; a database-only backup cannot restore encrypted credentials. The module creates restrictive permissions for this key. Hosting must also prevent HTTP access to storage.

Optional server configuration overrides are `SQUAD_SANDBOX_SECRET_KEY`, `SQUAD_LIVE_SECRET_KEY` and `SQUAD_ENCRYPTION_KEY`. A configured server Secret Key overrides its admin value. The encryption override must be base64 representing exactly 32 random bytes. Never put real credentials in the repository, an issue, a screenshot or a release ZIP.

The module validates raw-body SHA-512 HMAC webhook signatures and verifies successful payments independently against Squad. Browser return parameters cannot mark an order paid. Successful webhooks receive HTTP 200 after verification records Paid or a durable Review state; unresolved verification receives HTTP 503.

Pending and `init_unknown` attempts require verification before another charge is attempted. Review, mismatch and interrupted processing require investigation of the provider transaction, order history and stock. A verification recheck does not force an order to Paid. Stores with legacy MyISAM order tables cannot make all order side effects transactional, so interrupted updates are held for review rather than blindly replayed.

Uninstall keeps the ledger and encryption-key file for reconciliation, while OpenCart removes module settings. Refunds, chargebacks, recurring payments, virtual-account provisioning and automatic reconciliation of unrelated payment methods are outside this extension's scope.

## Reporting issues and contributing

Use the repository's Issues tab for bugs and compatibility reports. Include the extension version, OpenCart version, PHP version, theme/version, selected Test/Live mode, reproduction steps and relevant safe reason code. State whether the order already had an earlier attempt. Remove customer details, passwords, API keys, encrypted credentials, encryption keys and session tokens.

Security vulnerabilities should be reported privately using GitHub's private vulnerability-reporting feature when enabled. Otherwise request a private reporting channel from the maintainer before posting vulnerability details. Do not include live credentials in a report.

For changes, keep PHP 7.3 compatibility and the OpenCart 2.3.x directory layout. Test native first installation without pre-existing module permissions or a ledger, authenticated Save, duplicate notifications, failed verification and the affected checkout theme. Do not bypass amount/currency/reference validation, TLS checks or redirect-host restrictions to make a test pass.

Provider documentation: [Squad API Docs](https://docs.squadco.com/).
