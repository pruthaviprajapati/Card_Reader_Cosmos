<?php
/**
 * Upload + AI extraction. Uses Gemini 2.5 Flash when gemini_api_key is set,
 * falls back to Groq Llama 4 Vision when groq_api_key is set.
 * Both engines are kept intact — switch by setting/clearing keys in config.php.
 */
require_once __DIR__ . '/../lib/gemini.php';
require_once __DIR__ . '/../lib/groq.php';
require_once __DIR__ . '/../lib/validation.php';
require_once __DIR__ . '/../lib/duplicates.php';

/** Returns true if at least one AI engine is configured. */
function ai_enabled() {
    return gemini_enabled() || groq_enabled();
}

/** Returns true if the active engine can handle this file. */
function ai_can_handle($mime, $size) {
    if (gemini_enabled()) return gemini_can_handle($mime, $size);
    return groq_can_handle($mime, $size);
}

/**
 * Extract cards — tries Gemini 2.5 Flash first; falls back to Groq Llama 4
 * automatically if Gemini fails (rate limit, quota, outage).
 * Returns [cards, engine_label] where engine_label goes into ocr_results.
 */
function ai_extract_cards($filePath, $mime) {
    $c = config();
    if (gemini_enabled()) {
        try {
            $cards = gemini_extract_cards($filePath, $mime);
            return [$cards, 'gemini-' . ($c['gemini_model'] ?? 'gemini-2.5-flash')];
        } catch (Throwable $e) {
            error_log('[cosmos] Gemini failed, falling back to Groq: ' . $e->getMessage());
            if (!groq_enabled()) throw $e;
        }
    }
    $cards = groq_extract_cards($filePath, $mime);
    return [$cards, 'groq-' . ($c['groq_model'] ?? 'llama-4-scout')];
}

/** Normalise $_FILES['files'] (array or single) into a flat list. */
function normalize_files() {
    if (empty($_FILES['files'])) return [];
    $f = $_FILES['files'];
    $out = [];
    if (is_array($f['name'])) {
        $n = count($f['name']);
        for ($i = 0; $i < $n; $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i],
                          'type' => $f['type'][$i], 'size' => $f['size'][$i]];
            }
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $out[] = ['name' => $f['name'], 'tmp_name' => $f['tmp_name'], 'type' => $f['type'], 'size' => $f['size']];
    }
    return $out;
}

function normalized_company_key($name, $website) {
    $n = preg_replace('/[^a-z0-9]/', '', strtolower($name ?? ''));
    $domain = preg_replace('#^https?://#', '', strtolower($website ?? ''));
    $domain = preg_replace('/^www\./', '', $domain);
    $domain = explode('/', $domain)[0];
    $key = "$n::$domain";
    return strlen($key) > 2 ? substr($key, 0, 250) : 'unknown::' . time() . rand(100, 999);
}

function upsert_company($e, $geo) {
    if (empty($e['companyName'])) return null;
    $key = normalized_company_key($e['companyName'], $e['website'] ?? null);
    $existing = q1('SELECT id FROM companies WHERE normalizedKey = ?', [$key]);
    if ($existing) {
        exec_sql('UPDATE companies SET website=COALESCE(?,website), gstin=COALESCE(?,gstin),
                  industry=COALESCE(?,industry), city=COALESCE(?,city), state=COALESCE(?,state),
                  country=COALESCE(?,country), updatedAt=? WHERE id=?',
            [$e['website'] ?? null, $e['gstin'] ?? null, $e['industry'] ?? null,
             $geo['city'], $geo['state'], $geo['country'], now3(), $existing['id']]);
        return $existing['id'];
    }
    $id = uuid_v4();
    $domain = preg_replace('/^www\./', '', preg_replace('#^https?://#', '', strtolower($e['website'] ?? '')));
    $domain = explode('/', $domain)[0] ?: null;
    exec_sql('INSERT INTO companies (id, name, normalizedKey, website, domain, industry, gstin, city, state, country, postalCode, createdAt, updatedAt)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $e['companyName'], $key, $e['website'] ?? null, $domain, $e['industry'] ?? null,
         $e['gstin'] ?? null, $geo['city'], $geo['state'], $geo['country'], $e['postalCode'] ?? null, now3(), now3()]);
    return $id;
}

function entities_from_groq_card($card) {
    $contacts = (!empty($card['contacts']) && is_array($card['contacts']))
        ? $card['contacts']
        : [['name' => null, 'designation' => null, 'email' => null, 'phone' => null, 'mobile' => null, 'isPrimary' => true]];

    $primary = null;
    foreach ($contacts as $c) { if (!empty($c['isPrimary'])) { $primary = $c; break; } }
    if (!$primary) $primary = $contacts[0];

    $firstEmail = $primary['email'] ?? null;
    if (!$firstEmail) { foreach ($contacts as $c) { if (!empty($c['email'])) { $firstEmail = $c['email']; break; } } }

    return [
        'companyName'  => $card['company'] ?? null,
        'website'      => $card['website'] ?? null,
        'email'        => $firstEmail,
        'phonePrimary' => $primary['mobile'] ?? $primary['phone'] ?? null,
        'phoneSecondary' => null,
        'address'      => $card['address'] ?? null,
        'postalCode'   => $card['postalCode'] ?? null,
        'gstin'        => $card['gstin'] ?? null,
        'industry'     => $card['industry'] ?? null,
        'linkedin'     => $card['linkedin'] ?? null,
        'city'         => $card['city'] ?? null,
        'state'        => $card['state'] ?? null,
        'country'      => $card['country'] ?? null,
        'contacts'     => array_map(fn($c) => [
            'fullName'    => $c['name'] ?? null,
            'designation' => $c['designation'] ?? null,
            'department'  => null,
            'email'       => $c['email'] ?? null,
            'mobile'      => $c['mobile'] ?? null,
            'phone'       => $c['phone'] ?? null,
            'isPrimary'   => !empty($c['isPrimary']),
        ], $contacts),
        '_aiConfidence' => isset($card['confidence']) && is_numeric($card['confidence'])
            ? min($card['confidence'] / 100, 1) : null,
    ];
}

function persist_card_lead($upload, $cardIndex, $rawCard, $entities) {
    $v = validate_lead($entities);
    $geo = [
        'city'    => $entities['city']    ?: ($v['geo']['city']    ?? null),
        'state'   => $entities['state']   ?: ($v['geo']['state']   ?? null),
        'country' => $entities['country'] ?: ($v['geo']['country'] ?? null),
    ];
    $companyId = upsert_company($entities, $geo);
    $ai = $entities['_aiConfidence'] !== null ? $entities['_aiConfidence'] : 0;

    $cardId = uuid_v4();
    exec_sql('INSERT INTO cards (id, uploadId, pageIndex, cardIndex, croppedPath, rotationApplied, qualityScore, createdAt)
              VALUES (?,?,?,?,?,?,?,?)',
        [$cardId, $upload['id'], 0, $cardIndex, '', 0, $ai, now3()]);

    $engineLabel = $rawCard['_engine'] ?? 'ai-vision';
    exec_sql('INSERT INTO ocr_results (id, cardId, chosenEngine, rawText, confidence, engineResults, createdAt)
              VALUES (?,?,?,?,?,?,?)',
        [uuid_v4(), $cardId, 'MERGED', json_encode($rawCard), $ai,
         json_encode(['source' => $engineLabel]), now3()]);

    $leadId = uuid_v4();
    exec_sql('INSERT INTO leads (id, cardId, companyId, companyName, website, email, phonePrimary, phoneSecondary,
                address, city, state, country, postalCode, gstin, industry, linkedin, aiConfidence, validation, source,
                status, scannedAt, createdAt, updatedAt)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$leadId, $cardId, $companyId, $entities['companyName'], $entities['website'], $entities['email'],
         $entities['phonePrimary'], $entities['phoneSecondary'], $entities['address'], $geo['city'], $geo['state'],
         $geo['country'], $entities['postalCode'], $entities['gstin'], $entities['industry'], $entities['linkedin'],
         $ai, json_encode($v['fields']), $upload['source'], 'PENDING_REVIEW', now3(), now3(), now3()]);

    foreach ($entities['contacts'] as $c) {
        exec_sql('INSERT INTO contacts (id, leadId, fullName, designation, department, email, mobile, phone, isPrimary, createdAt)
                  VALUES (?,?,?,?,?,?,?,?,?,?)',
            [uuid_v4(), $leadId, $c['fullName'], $c['designation'], $c['department'],
             $c['email'], $c['mobile'], $c['phone'], $c['isPrimary'] ? 1 : 0, now3()]);
    }
    return $leadId;
}

function process_one_upload($user, $file) {
    $uploadId = uuid_v4();
    $mime = $file['type'] ?: 'application/octet-stream';
    exec_sql('INSERT INTO uploads (id, originalName, storedPath, mimeType, sizeBytes, source, status, uploadedById, createdAt, updatedAt)
              VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$uploadId, $file['name'], $file['name'], $mime, (int)$file['size'],
         $_POST['source'] ?? null, 'PENDING', $user['sub'], now3(), now3()]);

    $upload = ['id' => $uploadId, 'source' => $_POST['source'] ?? null];

    if (!ai_can_handle($mime, $file['size'])) {
        exec_sql('UPDATE uploads SET status=?, error=? WHERE id=?',
            ['FAILED', "Unsupported file type or size: $mime {$file['size']}B", $uploadId]);
        return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'FAILED',
                'error' => 'Unsupported file type or size', 'leadIds' => [], 'leads' => 0, 'cards' => 0, 'pages' => 1];
    }

    exec_sql('UPDATE uploads SET status=? WHERE id=?', ['EXTRACTING', $uploadId]);

    // Time the actual card read (AI vision extraction).
    $t0 = microtime(true);
    try {
        [$cards, $engineLabel] = ai_extract_cards($file['tmp_name'], $mime);
    } catch (Throwable $e) {
        $readMs = (int) round((microtime(true) - $t0) * 1000);
        exec_sql('UPDATE uploads SET status=?, error=? WHERE id=?', ['FAILED', $e->getMessage(), $uploadId]);
        return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'FAILED',
                'error' => $e->getMessage(), 'leadIds' => [], 'leads' => 0, 'cards' => 0, 'pages' => 1, 'ms' => $readMs];
    }
    $readMs = (int) round((microtime(true) - $t0) * 1000);

    $leadIds = [];
    foreach ($cards as $i => $card) {
        try {
            $card['_engine'] = $engineLabel;
            $entities = entities_from_groq_card($card);
            $leadIds[] = persist_card_lead($upload, $i, $card, $entities);
        } catch (Throwable $e) {
            error_log("[upload] card $i failed: " . $e->getMessage());
        }
    }
    exec_sql('UPDATE uploads SET status=? WHERE id=?', ['READY_FOR_REVIEW', $uploadId]);

    // 'ms' = exact time taken to read the card(s) on this photo.
    return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'READY_FOR_REVIEW',
            'pages' => 1, 'cards' => count($cards), 'leads' => count($leadIds), 'leadIds' => $leadIds, 'ms' => $readMs];
}

function route_upload() {
    @set_time_limit(150);
    $user = require_auth(true);
    $files = normalize_files();
    if (!$files) error_out('No files uploaded', 400);
    if (!ai_enabled()) error_out('No AI API key configured in config.php (set gemini_api_key or groq_api_key)', 503);

    $processed = [];
    foreach ($files as $f) { $processed[] = process_one_upload($user, $f); }

    // Refresh duplicate pairs once after the batch (cheap, blocking-keyed).
    try { dup_rescan(); } catch (Throwable $e) { /* non-critical */ }

    json_out(['processed' => $processed]);
}
