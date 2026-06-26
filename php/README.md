# Cosmos Lead Intelligence Platform — PHP Backend (Hostinger)

A pure **PHP 8 + MySQL** backend that runs on Hostinger shared hosting (no
Node.js, no Redis, no Python). Card extraction is done by **Groq Llama 4
Vision** over HTTPS. The frontend (`/index.html`) and this API are served from
the same domain, so there is no CORS.

## Layout (on the web root, e.g. `public_html/`)

```
index.html            ← the single-page app (frontend)
install.php           ← one-time admin seed, web root so the /api rewrite
                         does not intercept it (self-deletes after running)
.htaccess             ← routes /api/* and /health to the PHP front controller
api/
  index.php           ← router / front controller
  config.php          ← secrets (DB, JWT, Groq key) — NOT in git, upload via FTP
  config.example.php  ← template
  lib/                ← db, jwt, helpers, model, validation, groq, duplicates, crm
  routes/             ← auth, users, leads, upload, duplicates, analytics, export
```

## Deploy / go-live steps

1. **Upload** `index.html`, `.htaccess`, and the `api/` folder to `public_html/`.
2. **Create the MySQL tables** (already done) — see the schema in the project.
3. **Edit `api/config.php`** and paste your Groq key:
   ```php
   'groq_api_key' => 'gsk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
   ```
   (Get one free at https://console.groq.com → API Keys.)
4. **Run the installer once** — open `https://<domain>/install.php` in a
   browser. It creates the admin user and then deletes itself.
5. **Log in** at `https://<domain>` with the admin email/password from
   `config.php`, then change the password under Settings.

## Requirements

PHP 8.0+ with `pdo_mysql` and `curl` (both standard on Hostinger).

## Health check

`GET /health` → `{"status":"ok","db":"ok","engine":"php"}`
