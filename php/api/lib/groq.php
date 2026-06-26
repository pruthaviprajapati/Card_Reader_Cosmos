<?php
/**
 * Groq Llama 4 Vision — business-card extraction via cURL.
 * Key is read from config.php only (never from the request).
 */

function groq_prompt() {
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

function groq_enabled() {
    $c = config();
    return !empty($c['groq_api_key']);
}

function groq_can_handle($mime, $size) {
    return groq_enabled()
        && preg_match('#^image/(jpeg|png|webp|gif)$#', $mime ?? '')
        && ($size ?? 0) <= 3.8 * 1024 * 1024;
}

/**
 * Send an image to Groq and return the array of detected cards.
 * @throws Exception on API / parse errors.
 */
function groq_extract_cards($filePath, $mime) {
    $c = config();
    $key = $c['groq_api_key'];
    if (!$key) throw new Exception('GROQ_API_KEY not set in config.php');

    $b64   = base64_encode(file_get_contents($filePath));
    $media = ($mime === 'image/jpg') ? 'image/jpeg' : $mime;

    $payload = [
        'model'       => $c['groq_model'],
        'max_tokens'  => 8000,
        'temperature' => 0,
        'messages'    => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => groq_prompt()],
                ['type' => 'image_url', 'image_url' => ['url' => "data:$media;base64,$b64"]],
            ],
        ]],
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr   = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception("Groq connection error: $cerr");

    $data = json_decode($resp, true);
    if ($status === 401) throw new Exception('Groq: invalid API key — check config.php');
    if ($status === 429) throw new Exception('Groq: rate limit exceeded — try again in a moment');
    if ($status === 404 || (isset($data['error']['message']) && stripos($data['error']['message'], 'model') !== false)) {
        throw new Exception("Groq: model '{$c['groq_model']}' unavailable — update groq_model in config.php");
    }
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? "HTTP $status";
        throw new Exception("Groq API error: $msg");
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    if (!preg_match('/\{[\s\S]*\}/', $text, $m)) throw new Exception('Groq returned no JSON');

    $parsed = json_decode($m[0], true);
    if (isset($parsed['cards']) && is_array($parsed['cards'])) return $parsed['cards'];
    if (isset($parsed['company'])) return [$parsed];
    return [];
}
