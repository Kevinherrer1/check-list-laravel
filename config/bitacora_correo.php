<?php

return [
    'enabled' => env('BITACORA_SMTP_ENABLED', true),
    'method' => 'smtp',
    'smtp_host' => env('BITACORA_SMTP_HOST', '172.25.214.102'),
    'smtp_port' => (int) env('BITACORA_SMTP_PORT', 465),

    'remitentes' => [
        [
            'id' => 'Ysabel',
            'nombre' => 'Ysabel Mantilla',
            'from' => 'ysabel.mantilla@cvg.gob.ve',
            'smtp_user' => 'ysabel_mantilla',
        ],
        [
            'id' => 'Jessica',
            'nombre' => 'Jessica Alfonzo',
            'from' => 'jessica.alfonzo@cvg.gob.ve',
            'smtp_user' => 'jessica_alfonzo',
        ],
        [
            'id' => 'Darimar',
            'nombre' => 'Darimar Zambrano',
            'from' => 'darimar.zambrano@cvg.gob.ve',
            'smtp_user' => 'darimar_zambrano',
        ],
        [
            'id' => 'Norman',
            'nombre' => 'Norman Boccardo',
            'from' => 'norman.boccardo@cvg.gob.ve',
            'smtp_user' => 'norman_boccardo',
        ],
    ],

    'to_default' => env('BITACORA_MAIL_TO', ''),
    'cc_default' => env('BITACORA_MAIL_CC', ''),

    'contactos' => [
        ['nombre' => 'Maria Blanco', 'email' => 'maria.blanco@cvg.gob.ve'],
        ['nombre' => 'Norman Boccardo', 'email' => 'norman.boccardo@cvg.gob.ve'],
        ['nombre' => 'Oscar Mendez', 'email' => 'oscar.mendez@cvg.gob.ve'],
        ['nombre' => 'Carlos Brito', 'email' => 'carlos.brito@cvg.gob.ve'],
        ['nombre' => 'Jessica Alfonzo', 'email' => 'jessica.alfonzo@cvg.gob.ve'],
    ],

    // Misma agenda que Thunderbird (ldap_2.servers, sin clave).
    'ldap' => [
        'enabled' => env('BITACORA_LDAP_ENABLED', true),
        'host' => env('BITACORA_LDAP_HOST', 'pzosdgstdeb7.pzo.cvg.com'),
        'host_ip' => env('BITACORA_LDAP_HOST_IP', ''),
        'port' => (int) env('BITACORA_LDAP_PORT', 389),
        'base' => env('BITACORA_LDAP_BASE', 'dc=pzo,dc=cvg,dc=com'),
        'filter' => env('BITACORA_LDAP_FILTER', '(mail=*)'),
        'limit' => (int) env('BITACORA_LDAP_LIMIT', 800),
        'timeout' => (int) env('BITACORA_LDAP_TIMEOUT', 5),
    ],

    'asunto_plantilla' => 'Bitacora DBA - checklist {fecha}',
    'mensaje_default' => "{saludo},\n\nSe adjunta el checklist diario de respaldos del día {fecha}.\n\nSaludos,\n{remitente}\n",
];
