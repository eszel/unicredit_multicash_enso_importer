<?php

return [
    'importer' => [
        'input_dir' => __DIR__ . '/incoming',
        'imported_dir' => __DIR__ . '/_enso_imported',
        'error_dir' => __DIR__ . '/_enso_error',
        'allowed_extensions' => ['VMK'],
        'process_limit' => 0,
        'sort' => 'mtime_asc',
        'encoding_candidates' => ['UTF-8', 'CP852', 'CP850', 'Windows-1250', 'ISO-8859-2'],
    ],
    'enso' => [
        'base_url' => 'https://companyname.c.enso.hu/api',
        'endpoint' => '/bankkivonat-tetelek/bulk',
        'bearer_token' => '',
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'headers' => [],
    ],
    'payload' => [
        'include_raw_fields' => true,
        'forras_format' => 'unicredit_vmk',
        'only_credit_transactions' => true,
        'default_currency' => null,
        'max_items_per_request' => 500,
    ],
    'logging' => [
        'file' => __DIR__ . '/logs/importer.log',
        'stdout' => true,
    ],
];
