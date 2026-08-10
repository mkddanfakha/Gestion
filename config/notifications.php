<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Priorités
    |--------------------------------------------------------------------------
    */
    'priorities' => [
        'critical' => [
            'label' => 'Critique',
            'color' => 'danger',
            'icon' => 'bi-exclamation-octagon-fill',
        ],
        'warning' => [
            'label' => 'Avertissement',
            'color' => 'warning',
            'icon' => 'bi-exclamation-triangle-fill',
        ],
        'info' => [
            'label' => 'Information',
            'color' => 'info',
            'icon' => 'bi-info-circle-fill',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Statuts
    |--------------------------------------------------------------------------
    */
    'statuses' => [
        'active' => [
            'label' => 'Active',
        ],
        'resolved' => [
            'label' => 'Résolue',
        ],
        'archived' => [
            'label' => 'Archivée',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audiences
    |--------------------------------------------------------------------------
    */
    'audiences' => [
        'admin' => [
            'label' => 'Administrateurs',
            'role' => 'admin',
        ],
        'manager' => [
            'label' => 'Gestionnaires',
            'role' => 'gestionnaire',
        ],
        'seller' => [
            'label' => 'Vendeurs',
            'role' => 'vendeur',
        ],
        'custom' => [
            'label' => 'Utilisateurs ciblés',
            'role' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Types legacy → enum (compatibilité système existant)
    |--------------------------------------------------------------------------
    */
    'legacy_types' => [
        'sale_due_today' => 'invoice_due',
        'low_stock' => 'low_stock',
        'expiring_product' => 'product_expiring',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rôles autorisés pour les alertes inventaire / factures (UI legacy)
    |--------------------------------------------------------------------------
    */
    'inventory_alert_roles' => ['admin', 'gestionnaire'],
    'invoice_alert_roles' => ['admin', 'gestionnaire'],

    /*
    |--------------------------------------------------------------------------
    | Règles d'audience par type (NotificationAudienceResolver)
    |--------------------------------------------------------------------------
    */
    'audience_rules' => [
        'stock_out' => ['admin', 'manager'],
        'low_stock' => ['admin', 'manager'],
        'product_expired' => ['admin', 'manager'],
        'product_expiring' => ['admin', 'manager'],
        'invoice_due' => ['admin', 'manager'],
        'purchase_order_received' => ['admin', 'manager'],
        'delivery_note_received' => ['admin', 'manager'],
        'backup_success' => ['admin'],
        'backup_failed' => ['admin'],
        'user_created' => ['admin'],
        'user_deleted' => ['admin'],
        'system_error' => ['admin'],
        'system_info' => ['admin'],
        'sale_completed' => ['seller'],
        'sale_returned' => ['seller'],
        'sale_cancelled' => ['seller'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Types regroupables (anti-spam)
    |--------------------------------------------------------------------------
    */
    'groupable_types' => [
        'stock_out',
        'low_stock',
        'product_expired',
        'product_expiring',
        'invoice_due',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types limités au vendeur propriétaire
    |--------------------------------------------------------------------------
    */
    'seller_scoped_types' => [
        'sale_completed',
        'sale_returned',
        'sale_cancelled',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages contextuels vendeur (sans notification persistante)
    |--------------------------------------------------------------------------
    */
    'contextual_messages' => [
        'product_expired' => 'Ce produit est périmé. Vente impossible.',
        'insufficient_stock' => 'Stock insuffisant.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates de messages regroupés
    |--------------------------------------------------------------------------
    */
    'grouped_messages' => [
        'stock_out' => ':count produit(s) sont actuellement en rupture de stock.',
        'low_stock' => ':count produit(s) ont un stock faible.',
        'product_expired' => ':count produit(s) sont périmés.',
        'product_expiring' => ':count produit(s) expirent bientôt.',
        'invoice_due' => ':count facture(s) arrivent à échéance.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types legacy reconnus par l'API / le frontend actuel
    |--------------------------------------------------------------------------
    */
    'legacy_api_types' => [
        'sale_due_today',
        'low_stock',
        'expiring_product',
    ],

    /*
    |--------------------------------------------------------------------------
    | Types de notifications
    |--------------------------------------------------------------------------
    */
    'types' => [
        'stock_out' => [
            'label' => 'Rupture de stock',
            'priority' => 'critical',
            'entity_type' => 'product',
            'realtime' => true,
        ],
        'low_stock' => [
            'label' => 'Stock faible',
            'priority' => 'warning',
            'entity_type' => 'product',
            'realtime' => true,
        ],
        'product_expired' => [
            'label' => 'Produit expiré',
            'priority' => 'critical',
            'entity_type' => 'product',
            'realtime' => true,
        ],
        'product_expiring' => [
            'label' => 'Produit bientôt expiré',
            'priority' => 'warning',
            'entity_type' => 'product',
            'realtime' => true,
        ],
        'invoice_due' => [
            'label' => 'Échéance de facture',
            'priority' => 'info',
            'entity_type' => 'sale',
            'realtime' => true,
        ],
        'purchase_order_received' => [
            'label' => 'Bon de commande reçu',
            'priority' => 'info',
            'entity_type' => 'purchase_order',
            'realtime' => true,
        ],
        'sale_completed' => [
            'label' => 'Vente finalisée',
            'priority' => 'info',
            'entity_type' => 'sale',
            'realtime' => true,
        ],
        'sale_returned' => [
            'label' => 'Retour de vente',
            'priority' => 'info',
            'entity_type' => 'sale',
            'realtime' => true,
        ],
        'sale_cancelled' => [
            'label' => 'Vente annulée',
            'priority' => 'warning',
            'entity_type' => 'sale',
            'realtime' => true,
        ],
        'delivery_note_received' => [
            'label' => 'Bon de livraison reçu',
            'priority' => 'info',
            'entity_type' => 'delivery_note',
            'realtime' => true,
        ],
        'backup_success' => [
            'label' => 'Sauvegarde réussie',
            'priority' => 'info',
            'entity_type' => null,
            'realtime' => false,
        ],
        'backup_failed' => [
            'label' => 'Échec de sauvegarde',
            'priority' => 'critical',
            'entity_type' => null,
            'realtime' => true,
        ],
        'user_created' => [
            'label' => 'Utilisateur créé',
            'priority' => 'info',
            'entity_type' => 'user',
            'realtime' => false,
        ],
        'user_deleted' => [
            'label' => 'Utilisateur supprimé',
            'priority' => 'warning',
            'entity_type' => 'user',
            'realtime' => false,
        ],
        'system_error' => [
            'label' => 'Erreur système',
            'priority' => 'critical',
            'entity_type' => null,
            'realtime' => true,
        ],
        'system_info' => [
            'label' => 'Information système',
            'priority' => 'info',
            'entity_type' => null,
            'realtime' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Délais (secondes)
    |--------------------------------------------------------------------------
    */
    'delays' => [
        'realtime_reload' => 500,
        'expiring_product_reload' => 1000,
        'recent_limit' => 50,
        'archive_after_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Module NotificationCenter
    |--------------------------------------------------------------------------
    */
    'user_model' => \App\Models\User::class,

    'realtime' => [
        'driver' => env('NOTIFICATION_REALTIME_DRIVER', 'pusher'),
        'channel' => 'user.{userId}.notifications',
        'event' => 'notification.sent',
        /** Types toujours diffusés immédiatement via Pusher (sans file d'attente). */
        'immediate_types' => [
            'low_stock',
            'stock_out',
            'product_expiring',
            'product_expired',
            'invoice_due',
            'test',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Préférences utilisateur (NotificationCenter frontend)
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Production — cache, queues, scheduler, monitoring
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'settings_ttl_minutes' => (int) env('NOTIFICATION_CACHE_SETTINGS_TTL', 10),
        'user_preferences_ttl_minutes' => (int) env('NOTIFICATION_CACHE_USER_PREFS_TTL', 10),
        'unread_count_ttl_seconds' => (int) env('NOTIFICATION_CACHE_UNREAD_TTL', 60),
    ],

    'queue' => [
        'enabled' => env('NOTIFICATION_QUEUE_ENABLED', true),
        'connection' => env('NOTIFICATION_QUEUE_CONNECTION'),
        'name' => env('NOTIFICATION_QUEUE_NAME', 'notifications'),
    ],

    'scheduler' => [
        'enabled' => env('NOTIFICATION_SCHEDULER_ENABLED', true),
        'archive_resolved_after_days' => (int) env('NOTIFICATION_ARCHIVE_RESOLVED_DAYS', 30),
        'archive_resolved_at' => env('NOTIFICATION_ARCHIVE_RESOLVED_AT', '03:30'),
        'delete_archived_after_days' => (int) env('NOTIFICATION_DELETE_ARCHIVED_DAYS', 90),
        'delete_archived_at' => env('NOTIFICATION_DELETE_ARCHIVED_AT', '04:00'),
        'cleanup_orphans_at' => env('NOTIFICATION_CLEANUP_ORPHANS_AT', '04:30'),
        'cleanup_expired_at' => env('NOTIFICATION_CLEANUP_EXPIRED_AT', '05:00'),
        'optimize_tables_at' => env('NOTIFICATION_OPTIMIZE_TABLES_AT', '03:00'),
        'use_queue_for_scheduled_tasks' => env('NOTIFICATION_SCHEDULER_USE_QUEUE', true),
    ],

    'monitoring' => [
        'enabled' => env('NOTIFICATION_MONITORING_ENABLED', true),
    ],

    'user_preferences' => [
        'toast_positions' => [
            'bottom-right' => 'Bas droite',
            'bottom-left' => 'Bas gauche',
            'top-right' => 'Haut droite',
            'top-left' => 'Haut gauche',
        ],
        'default_toast_durations' => [
            'critical' => 8000,
            'warning' => 6000,
            'info' => 4500,
        ],
        'default_sound_volume' => 0.15,
        'sound_profiles' => [
            'silent' => [
                'label' => 'Silencieux',
                'frequencies' => ['info' => 0, 'warning' => 0, 'critical' => 0],
            ],
            'discrete' => [
                'label' => 'Son discret',
                'frequencies' => ['info' => 520, 'warning' => 620, 'critical' => 720],
            ],
            'classic' => [
                'label' => 'Son classique',
                'frequencies' => ['info' => 620, 'warning' => 740, 'critical' => 880],
            ],
            'critical' => [
                'label' => 'Son critique',
                'frequencies' => ['info' => 680, 'warning' => 820, 'critical' => 980],
            ],
        ],
    ],

];
