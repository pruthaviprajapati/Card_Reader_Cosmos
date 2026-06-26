<?php
/**
 * Cosmos LIP — configuration template.
 * Copy this to `config.php` and fill in real values. config.php is gitignored
 * and uploaded to the server via FTP only (never committed).
 */
return [
    // ── Database (Hostinger MySQL) ──────────────────────────────────────
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'u743639974_ocrdbcosmos',
        'user' => 'u743639974_ocrusrcosmos',
        'pass' => 'YOUR_DB_PASSWORD',
    ],

    // ── Auth ────────────────────────────────────────────────────────────
    'jwt_secret'      => 'CHANGE_ME_LONG_RANDOM_SECRET',
    'jwt_expires_sec' => 12 * 60 * 60,           // 12 hours
    'admin_signup_code' => 'CHANGE_ME_SIGNUP_CODE', // empty disables super-admin signup

    // ── Groq Llama 4 Vision ─────────────────────────────────────────────
    'groq_api_key' => '',                        // gsk_...  (add before go-live)
    'groq_model'   => 'meta-llama/llama-4-scout-17b-16e-instruct',

    // ── First-run admin (created by install.php) ────────────────────────
    'admin_email'    => 'admin@cosmos.in',
    'admin_password' => 'CHANGE_ME_ADMIN_PASSWORD',
];
