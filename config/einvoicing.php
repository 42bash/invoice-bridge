<?php

$bool = fn (string $key, bool $default) => filter_var(env($key, $default), FILTER_VALIDATE_BOOLEAN);

return [

    /*
    | Master switches. Everything that talks to SUPER PDP for real (upload,
    | lifecycle statuses, payment reports) is refused while `enabled` is false.
    | Preparing and validating Factur-X files stays available.
    */
    'enabled' => $bool('EINVOICING_ENABLED', false),
    // Emission (sales invoices, credits, payment statuses) also needs this one,
    // so that reception can run on its own.
    'send_enabled' => $bool('EINVOICING_SEND_ENABLED', false),
    'auto_send' => $bool('EINVOICING_AUTO_SEND', false),
    'auto_receive' => $bool('EINVOICING_AUTO_RECEIVE', false),
    'report_payments' => $bool('EINVOICING_REPORT_PAYMENTS', false),
    'auto_create_vendors' => $bool('EINVOICING_AUTO_CREATE_VENDORS', true),

    // Set only on the isolated Invoice Ninja TEST instance: allows einvoicing:recette.
    'test_instance' => $bool('EINVOICING_TEST_INSTANCE', false),

    // Invoices dated before this day are never proposed for transmission.
    'send_from_date' => env('EINVOICING_SEND_FROM_DATE', '2026-09-01'),

    'invoice_ninja' => [
        'url' => rtrim((string) env('INVOICE_NINJA_URL', ''), '/'),
        'token' => env('INVOICE_NINJA_API_TOKEN'),
        'timeout' => (int) env('INVOICE_NINJA_TIMEOUT', 30),
        // PDF rendering by Invoice Ninja (local Chromium) can take 15-60 s.
        'pdf_timeout' => (int) env('INVOICE_NINJA_PDF_TIMEOUT', 180),
        'write_back' => $bool('EINVOICING_WRITE_BACK', true),
        'upload_documents' => $bool('EINVOICING_UPLOAD_DOCUMENTS', true),
        // Lifecycle events are also written to the Invoice Ninja activity history.
        'activity_notes' => $bool('EINVOICING_ACTIVITY_NOTES', true),

        // Invoice / credit custom fields used to drive and display the e-invoice.
        'fields' => [
            'action' => env('EINVOICING_FIELD_ACTION', 'custom_value1'),
            'status' => env('EINVOICING_FIELD_STATUS', 'custom_value2'),
            'reference' => env('EINVOICING_FIELD_REFERENCE', 'custom_value3'),
            'detail' => env('EINVOICING_FIELD_DETAIL', 'custom_value4'),
        ],
        'action_send' => env('EINVOICING_ACTION_SEND', 'Transmettre à SuperPDP'),
        'action_send_deposit' => env('EINVOICING_ACTION_SEND_DEPOSIT', 'Transmettre à SuperPDP (acompte)'),
        'action_skip' => env('EINVOICING_ACTION_SKIP', 'Ne pas transmettre'),
        // Prepares a blocked document again (after fixing the client or a mention), then reverts to action_send.
        'action_retry' => env('EINVOICING_ACTION_RETRY', 'Relancer la transmission'),
        // Earlier labels, still understood on existing documents.
        'action_aliases' => [
            'À transmettre' => 'send',
            'À transmettre (acompte)' => 'send_deposit',
            'Relancer' => 'retry',
        ],

        'expense_fields' => [
            'reference' => env('EINVOICING_EXPENSE_FIELD_REFERENCE', 'custom_value1'),
            'status' => env('EINVOICING_EXPENSE_FIELD_STATUS', 'custom_value2'),
        ],
        'expense_category' => env('EINVOICING_EXPENSE_CATEGORY'),
    ],

    'superpdp' => [
        'api_url' => rtrim((string) env('SUPERPDP_API_URL', 'https://api.superpdp.tech'), '/'),
        'client_id' => env('SUPERPDP_CLIENT_ID'),
        'client_secret' => env('SUPERPDP_CLIENT_SECRET'),
        // sandbox | production. Checked against GET /companies/me before any write.
        'environment' => env('SUPERPDP_ENVIRONMENT', 'sandbox'),
        'timeout' => (int) env('SUPERPDP_TIMEOUT', 60),
        // In sandbox, every buyer address is replaced by this sandbox address.
        'sandbox_buyer_address' => env('SUPERPDP_SANDBOX_BUYER_ADDRESS'),
        'price_per_invoice' => (float) env('SUPERPDP_PRICE_PER_INVOICE', 0.01),
        'annual_minimum' => (float) env('SUPERPDP_ANNUAL_MINIMUM', 10.0),
        // Sandbox only: the second test company (Tricatel) used by einvoicing:sandbox-peer.
        'peer_client_id' => env('SUPERPDP_PEER_CLIENT_ID'),
        'peer_client_secret' => env('SUPERPDP_PEER_CLIENT_SECRET'),
    ],

    /*
    | Seller identity. Empty values are taken from SUPER PDP (GET /companies/me
    | and the company's own directory entries), which is the verified legal
    | identity, then from the Invoice Ninja company settings.
    */
    'seller' => [
        'name' => env('EINVOICING_SELLER_NAME'),
        'trading_name' => env('EINVOICING_SELLER_TRADING_NAME'),
        'siren' => env('EINVOICING_SELLER_SIREN'),
        'siret' => env('EINVOICING_SELLER_SIRET'),
        'vat_number' => env('EINVOICING_SELLER_VAT_NUMBER'),
        'electronic_address' => env('EINVOICING_SELLER_ELECTRONIC_ADDRESS'),
        'address_line1' => env('EINVOICING_SELLER_ADDRESS_LINE1'),
        'address_line2' => env('EINVOICING_SELLER_ADDRESS_LINE2'),
        'post_code' => env('EINVOICING_SELLER_POST_CODE'),
        'city' => env('EINVOICING_SELLER_CITY'),
        'country' => env('EINVOICING_SELLER_COUNTRY', 'FR'),
        'email' => env('EINVOICING_SELLER_EMAIL'),
        'phone' => env('EINVOICING_SELLER_PHONE'),
        'iban' => env('EINVOICING_SELLER_IBAN'),
        'bic' => env('EINVOICING_SELLER_BIC'),
    ],

    'vat' => [
        // true: the company is under "franchise en base" and must never charge VAT.
        // Default false: a distributor must opt in. An existing .env that sets the variable is unchanged.
        'franchise' => $bool('EINVOICING_VAT_FRANCHISE', false),
        // Kept as a config value so `einvoicing:check` still works after config:cache.
        'franchise_set' => env('EINVOICING_VAT_FRANCHISE') !== null && env('EINVOICING_VAT_FRANCHISE') !== '',
        // Reason given for 0 % lines (category E): code BT-121 and text BT-120.
        'exemption_code' => env('EINVOICING_VAT_EXEMPTION_CODE', 'VATEX-FR-FRANCHISE'),
        'exemption_text' => env('EINVOICING_VAT_EXEMPTION_TEXT', 'TVA non applicable, art. 293 B du CGI'),
    ],

    // BR-FR-05: mandatory B2B legal notes (art. L441-9 du Code de commerce).
    'notes' => [
        'PMT' => env('EINVOICING_NOTE_PMT', 'Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 €.'),
        'PMD' => env('EINVOICING_NOTE_PMD', 'Pénalités de retard : trois fois le taux d\'intérêt légal.'),
        'AAB' => env('EINVOICING_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé.'),
    ],

    // BT-23: B (goods), S (services) or M (mixed).
    'business_process' => env('EINVOICING_BUSINESS_PROCESS', 'S'),
    'payment_terms' => env('EINVOICING_PAYMENT_TERMS', 'Paiement à réception de facture.'),

    'dashboard' => [
        'user' => env('EINVOICING_DASHBOARD_USER', 'admin'),
        'password_hash' => env('EINVOICING_DASHBOARD_PASSWORD_HASH'),
    ],

    // Shared secret expected in the X-Einvoicing-Secret header of Invoice Ninja webhooks.
    'webhook_secret' => env('EINVOICING_WEBHOOK_SECRET'),

    // Technical journal only; archived invoices are never purged automatically.
    'log_retention_days' => (int) env('EINVOICING_LOG_RETENTION_DAYS', 400),

    // Alerts: always in the dashboard journal; also by e-mail (MAIL_* settings) and/or webhook when set.
    'notify' => [
        'mail_to' => env('EINVOICING_NOTIFY_MAIL_TO'),
        // Optional HTTP mail API (Mailtrap-compatible). Unused when MAIL_MAILER=smtp.
        'mailtrap_token' => env('EINVOICING_NOTIFY_MAILTRAP_TOKEN'),
        'mailtrap_host' => env('EINVOICING_NOTIFY_MAILTRAP_HOST', 'send.api.mailtrap.io'),
        'mailtrap_from' => env('EINVOICING_NOTIFY_MAILTRAP_FROM'),
        // ntfy / Gotify style: POST of the plain-text message, title in the "Title" header.
        'webhook_url' => env('EINVOICING_NOTIFY_WEBHOOK_URL'),
        'events' => env('EINVOICING_NOTIFY_EVENTS', 'received,rejected,sync_failed,credentials,outage'),
        // Consecutive failed passes before a "sync failed" alert (one pass every sync_every_minutes).
        'failures_after' => (int) env('EINVOICING_NOTIFY_FAILURES_AFTER', 2),
        'outage_minutes' => (int) env('EINVOICING_NOTIFY_OUTAGE_MINUTES', 60),
        'dashboard_url' => env('EINVOICING_DASHBOARD_URL', env('APP_URL')),
    ],

    'schedule' => [
        'sync_every_minutes' => (int) env('EINVOICING_SYNC_EVERY_MINUTES', 10),
    ],
];
