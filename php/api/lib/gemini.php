<?php
/**
 * Google Gemini 2.5 Flash — business-card extraction via cURL.
 * Key is read from config.php only (never from the request).
 * Uses the same prompt and return format as groq.php so upload.php
 * can switch between engines transparently.
 */

function gemini_enabled() {
    $c = config();
    return !empty($c['gemini_api_key']);
}

function gemini_can_handle($mime, $size) {
    // Accept image/jpg (alias) as well as image/jpeg
    $m = ($mime === 'image/jpg') ? 'image/jpeg' : ($mime ?? '');
    return gemini_enabled()
        && preg_match('#^image/(jpeg|png|webp|gif|heic|heif)$#', $m)
        && ($size ?? 0) <= 7 * 1024 * 1024;
}

/**
 * Send an image to Gemini 2.5 Flash and return the array of detected cards.
 * @throws Exception on API / parse errors.
 */
function gemini_extract_cards($filePath, $mime) {
    // Rate-limit guard: Gemini free tier allows 10–15 RPM.
    // Enforce a minimum 4-second gap between consecutive calls within one request.
    static $lastCallAt = 0;
    $gap = microtime(true) - $lastCallAt;
    if ($lastCallAt > 0 && $gap < 4.0) {
        usleep((int) ((4.0 - $gap) * 1_000_000));
    }
    $lastCallAt = microtime(true);

    $c = config();
    $key = $c['gemini_api_key'];
    if (!$key) throw new Exception('GEMINI_API_KEY not set in config.php');

    $model = $c['gemini_model'] ?? 'gemini-2.5-flash';
    $b64   = base64_encode(file_get_contents($filePath));
    $media = ($mime === 'image/jpg') ? 'image/jpeg' : $mime;

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => gemini_prompt()],
                ['inline_data' => ['mime_type' => $media, 'data' => $b64]],
            ],
        ]],
        'generationConfig' => [
            'temperature'     => 0,
            'maxOutputTokens' => 8192,
        ],
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception("Gemini connection error: $cerr");

    $data = json_decode($resp, true);

    if ($status === 400) {
        $msg = $data['error']['message'] ?? "Bad request";
        throw new Exception("Gemini API error: $msg");
    }
    if ($status === 401 || $status === 403) {
        throw new Exception('Gemini: invalid API key — check config.php');
    }
    if ($status === 429) {
        throw new Exception('Gemini: quota exceeded — try again later');
    }
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? "HTTP $status";
        throw new Exception("Gemini API error: $msg");
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!preg_match('/\{[\s\S]*\}/', $text, $m)) throw new Exception('Gemini returned no JSON');

    $parsed = json_decode($m[0], true);
    if (isset($parsed['cards']) && is_array($parsed['cards'])) return $parsed['cards'];
    if (isset($parsed['company'])) return [$parsed];
    return [];
}

function gemini_prompt() {
    return <<<'PROMPT'
You are a business-card data extraction engine for an Indian B2B CRM.
The image may contain ONE card or MANY business cards on a single page. Detect EVERY distinct card and extract its data separately.

Return ONLY a valid JSON object — no markdown, no commentary — in exactly this shape:
{
  "cards": [
    {
      "company": "string or null",
      "website": "domain without http/www — string or null",
      "gstin": "15-char Indian GST number e.g. 27ABCDE1234F1Z5 — string or null",
      "address": "full street address — string or null",
      "city": "string or null",
      "state": "full state name. Derive from GSTIN first 2 digits if present: 24=Gujarat 27=Maharashtra 29=Karnataka 33=Tamil Nadu 07=Delhi 06=Haryana 08=Rajasthan 09=Uttar Pradesh 36=Telangana 23=Madhya Pradesh 19=West Bengal 03=Punjab 30=Goa — string or null",
      "country": "string or null — default India if a +91 number or a GSTIN is present",
      "postalCode": "PIN code — string or null",
      "linkedin": "LinkedIn URL or handle — string or null",
      "industry": "industry sector e.g. Manufacturing, Precision Machining, Automotive, Electronics — string or null",
      "contacts": [
        {
          "name": "person full name — string or null",
          "designation": "job title — string or null",
          "email": "string or null",
          "phone": "office/landline with country code — string or null",
          "mobile": "mobile/cell with country code — string or null",
          "isPrimary": true
        }
      ],
      "confidence": 90
    }
  ]
}

Rules:
- List every person found on a card in that card's contacts array.
- The first / main person on each card has isPrimary true, others false.
- If a field is not present, use null. Do not invent data.
- confidence is an integer 0-100 reflecting how clearly the card was read.
- If no business card is visible, return {"cards": []}.
PROMPT;
}
