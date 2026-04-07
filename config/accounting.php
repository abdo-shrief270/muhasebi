<?php

return [
    'default_accounts' => [
        'cash' => env('ACCOUNT_CODE_CASH', '1111'),
        'bank' => env('ACCOUNT_CODE_BANK', '1112'),
        'accounts_receivable' => env('ACCOUNT_CODE_AR', '1121'),
        'vat_output' => env('ACCOUNT_CODE_VAT_OUTPUT', '2131'),
        'wht_services' => env('ACCOUNT_CODE_WHT_SERVICES', '2132'),
        'wht_supplies' => env('ACCOUNT_CODE_WHT_SUPPLIES', '2133'),
        'wht_equipment' => env('ACCOUNT_CODE_WHT_EQUIPMENT', '2134'),
        'revenue' => env('ACCOUNT_CODE_REVENUE', '4110'),
        'accounts_payable' => env('ACCOUNT_CODE_AP', '2111'),
        'vat_input' => env('ACCOUNT_CODE_VAT_INPUT', '2135'),
    ],
];
