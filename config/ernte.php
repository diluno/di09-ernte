<?php

return [

    /*
    | The first login and the business details BootstrapSeeder creates on a fresh
    | installation. Read here rather than with env() in the seeder, which returns
    | nothing once the configuration is cached. There is deliberately no default
    | password: an installation must never come up with a guessable one.
    */

    'owner' => [
        'email' => env('ERNTE_USER_EMAIL', 'owner@ernte.local'),
        'name' => env('ERNTE_USER_NAME', 'Owner'),
        'password' => env('ERNTE_USER_PASSWORD'),
    ],

    'business' => [
        'name' => env('BUSINESS_NAME', 'Your Name'),
        'address_line_1' => env('BUSINESS_ADDRESS_LINE_1', ''),
        'postal_code' => env('BUSINESS_POSTAL_CODE', ''),
        'city' => env('BUSINESS_CITY', ''),
        'country' => env('BUSINESS_COUNTRY', 'CH'),
        'uid' => env('BUSINESS_UID', ''),
        'vat_id' => env('BUSINESS_VAT_ID', ''),
        'iban' => env('BUSINESS_IBAN', ''),
        'qr_iban' => env('BUSINESS_QR_IBAN', ''),
        'email' => env('BUSINESS_EMAIL', ''),
        'default_currency' => env('BUSINESS_DEFAULT_CURRENCY', 'CHF'),
        'default_vat_rate' => env('BUSINESS_DEFAULT_VAT_RATE', '8.10'),
        'reminder_days_after_due' => (int) env('BUSINESS_REMINDER_DAYS_AFTER_DUE', 7),
    ],

];
