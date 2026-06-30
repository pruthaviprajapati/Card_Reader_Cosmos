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

    // Only these email domains may register / log in. Empty array = allow any.
    'allowed_email_domains' => ['cosmos.in', 'cosmos-cls.in'],

    // ── AI Vision (Gemini preferred; Groq is fallback) ──────────────────
    // Set gemini_api_key to use Google Gemini 2.5 Flash (Google Workspace free).
    // Leave empty to fall back to Groq Llama 4 Vision.
    'gemini_api_key' => '',                      // AIzaSy...  (Google AI Studio key)
    'gemini_model'   => 'gemini-2.5-flash',

    // ── Groq Llama 4 Vision (fallback) ──────────────────────────────────
    'groq_api_key' => '',                        // gsk_...  (used only if gemini_api_key is empty)
    'groq_model'   => 'meta-llama/llama-4-scout-17b-16e-instruct',

    // ── First-run admin (created by install.php) ────────────────────────
    'admin_email'    => 'admin@cosmos.in',
    'admin_password' => 'CHANGE_ME_ADMIN_PASSWORD',
];
