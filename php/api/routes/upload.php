<?php
/**
 * Upload + Groq extraction. Receives multipart files[], runs each through
 * Groq Llama 4 Vision, and persists Card + OcrResult + Lead + Contacts.
 */
require_once __DIR__ . '/../lib/groq.php';
require_once __DIR__ . '/../lib/validation.php';
require_once __DIR__ . '/../lib/duplicates.php';

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

    exec_sql('INSERT INTO ocr_results (id, cardId, chosenEngine, rawText, confidence, engineResults, createdAt)
              VALUES (?,?,?,?,?,?,?)',
        [uuid_v4(), $cardId, 'MERGED', json_encode($rawCard), $ai,
         json_encode(['source' => 'groq-vision', 'model' => config()['groq_model']]), now3()]);

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

    if (!groq_can_handle($mime, $file['size'])) {
        exec_sql('UPDATE uploads SET status=?, error=? WHERE id=?',
            ['FAILED', "Unsupported file type or size: $mime {$file['size']}B", $uploadId]);
        return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'FAILED',
                'error' => 'Unsupported file type or size', 'leadIds' => [], 'leads' => 0, 'cards' => 0, 'pages' => 1];
    }

    exec_sql('UPDATE uploads SET status=? WHERE id=?', ['EXTRACTING', $uploadId]);

    try {
        $cards = groq_extract_cards($file['tmp_name'], $mime);
    } catch (Throwable $e) {
        exec_sql('UPDATE uploads SET status=?, error=? WHERE id=?', ['FAILED', $e->getMessage(), $uploadId]);
        return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'FAILED',
                'error' => $e->getMessage(), 'leadIds' => [], 'leads' => 0, 'cards' => 0, 'pages' => 1];
    }

    $leadIds = [];
    foreach ($cards as $i => $card) {
        try {
            $entities = entities_from_groq_card($card);
            $leadIds[] = persist_card_lead($upload, $i, $card, $entities);
        } catch (Throwable $e) {
            error_log("[upload] card $i failed: " . $e->getMessage());
        }
    }
    exec_sql('UPDATE uploads SET status=? WHERE id=?', ['READY_FOR_REVIEW', $uploadId]);

    return ['id' => $uploadId, 'name' => $file['name'], 'status' => 'READY_FOR_REVIEW',
            'pages' => 1, 'cards' => count($cards), 'leads' => count($leadIds), 'leadIds' => $leadIds];
}

function route_upload() {
    @set_time_limit(150);
    $user = require_auth(true);
    $files = normalize_files();
    if (!$files) error_out('No files uploaded', 400);
    if (!groq_enabled()) error_out('GROQ_API_KEY not set in config.php', 503);

    $processed = [];
    foreach ($files as $f) { $processed[] = process_one_upload($user, $f); }

    // Refresh duplicate pairs once after the batch (cheap, blocking-keyed).
    try { dup_rescan(); } catch (Throwable $e) { /* non-critical */ }

    json_out(['processed' => $processed]);
}
