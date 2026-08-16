<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk de stockage des pièces jointes
    |--------------------------------------------------------------------------
    |
    | Par défaut : disque "local" (storage/app/private) — fichiers non publics.
    |
    */
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Taille maximale par fichier (Ko)
    |--------------------------------------------------------------------------
    |
    | 10240 Ko = 10 Mo. Doit rester cohérent avec upload_max_filesize / post_max_size.
    |
    */
    'max_size' => (int) env('ATTACHMENTS_MAX_SIZE', 10240),

    /*
    |--------------------------------------------------------------------------
    | Nombre maximal de fichiers par entité
    |--------------------------------------------------------------------------
    */
    'max_files' => (int) env('ATTACHMENTS_MAX_FILES', 10),

    'allowed_extensions' => [
        'pdf',
        'jpg',
        'jpeg',
        'png',
        'webp',
    ],

    'allowed_mimes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    'blocked_extensions' => [
        'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg',
        'exe', 'bat', 'sh', 'cmd', 'com', 'msi', 'dll',
    ],

];
