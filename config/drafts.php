<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Durée de conservation des brouillons (jours)
    |--------------------------------------------------------------------------
    */
    'expiration_days' => (int) env('DRAFT_EXPIRATION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Types de formulaires autorisés
    |--------------------------------------------------------------------------
    */
    'form_types' => [
        'customer',
        'product',
        'expense',
        'sale',
        'quote',
        'purchase_order',
        'delivery_note',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types synchronisés côté serveur
    |--------------------------------------------------------------------------
    */
    'server_sync_types' => [
        'sale',
        'quote',
        'purchase_order',
        'delivery_note',
    ],
];
