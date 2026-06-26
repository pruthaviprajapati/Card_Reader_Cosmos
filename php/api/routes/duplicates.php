<?php
/** Duplicate routes: list, scan, merge, dismiss. */
require_once __DIR__ . '/../lib/duplicates.php';

function route_duplicates_list() {
    require_auth(true);
    $rows = q("SELECT * FROM duplicates WHERE status = 'OPEN' ORDER BY score DESC");
    $out = [];
    foreach ($rows as $d) {
        $a = q1('SELECT * FROM leads WHERE id = ?', [$d['leadAId']]);
        $b = q1('SELECT * FROM leads WHERE id = ?', [$d['leadBId']]);
        if (!$a || !$b) continue;
        $cs = contacts_by_lead([$a['id'], $b['id']]);
        $out[] = [
            'id'     => $d['id'],
            'score'  => (float) $d['score'],
            'reasons'=> json_decode($d['reasons'] ?: '[]', true),
            'status' => $d['status'],
            'leadA'  => serialize_lead($a, $cs[$a['id']] ?? []),
            'leadB'  => serialize_lead($b, $cs[$b['id']] ?? []),
        ];
    }
    json_out($out);
}

function route_duplicates_scan() {
    require_role('ADMIN', 'REVIEWER');
    $found = dup_rescan();
    json_out(['found' => $found]);
}

function route_duplicates_dismiss() {
    $user = require_role('ADMIN', 'REVIEWER');
    $b = body();
    $ids = $b['ids'] ?? (isset($b['id']) ? [$b['id']] : []);
    if (!$ids) error_out('No duplicate id provided', 400);
    $place = implode(',', array_fill(0, count($ids), '?'));
    exec_sql("UPDATE duplicates SET status='DISMISSED' WHERE id IN ($place)", $ids);
    audit($user['sub'], 'DUPLICATE_DISMISS', 'Duplicate', null, ['ids' => $ids]);
    json_out(['dismissed' => count($ids)]);
}

const MERGE_FIELDS = [
    'companyName', 'website', 'email', 'phonePrimary', 'phoneSecondary', 'address',
    'city', 'state', 'country', 'postalCode', 'gstin', 'industry', 'linkedin',
    'twitter', 'facebook', 'instagram', 'youtube', 'whatsapp', 'qrCodeData', 'notes',
];

function route_duplicates_merge() {
    $user = require_role('ADMIN', 'REVIEWER');
    $b = body();
    $keepId = $b['keepId'] ?? null; $mergeId = $b['mergeId'] ?? null;
    if (!$keepId || !$mergeId || $keepId === $mergeId) {
        error_out('keepId and mergeId (different) are required', 400);
    }
    $keep = q1('SELECT * FROM leads WHERE id = ?', [$keepId]);
    $gone = q1('SELECT * FROM leads WHERE id = ?', [$mergeId]);
    if (!$keep || !$gone) error_out('Lead not found', 404);

    $keepContacts = q('SELECT * FROM contacts WHERE leadId = ?', [$keepId]);
    $goneContacts = q('SELECT * FROM contacts WHERE leadId = ?', [$mergeId]);

    // Fill blank fields on the kept lead from the merged lead.
    $set = []; $params = [];
    foreach (MERGE_FIELDS as $f) {
        $cur = $keep[$f] ?? null;
        if (($cur === null || $cur === '') && !empty($gone[$f])) { $set[] = "$f = ?"; $params[] = $gone[$f]; }
    }

    $keyOf = function ($c) {
        $mob = preg_replace('/\D/', '', $c['mobile'] ?: ($c['phone'] ?: ''));
        return strtolower($c['email'] ?: '') . '|' . $mob;
    };
    $have = [];
    foreach ($keepContacts as $c) { $have[$keyOf($c)] = true; }
    $newContacts = [];
    foreach ($goneContacts as $c) {
        $k = $keyOf($c);
        if ($k === '|' || isset($have[$k])) continue;
        $have[$k] = true; $newContacts[] = $c;
    }

    db()->beginTransaction();
    try {
        if ($set) {
            $params[] = now3(); $params[] = $keepId;
            exec_sql('UPDATE leads SET ' . implode(', ', $set) . ', updatedAt = ? WHERE id = ?', $params);
        }
        foreach ($newContacts as $c) {
            exec_sql('INSERT INTO contacts (id, leadId, fullName, designation, department, email, mobile, phone, isPrimary, createdAt)
                      VALUES (?,?,?,?,?,?,?,?,0,?)',
                [uuid_v4(), $keepId, $c['fullName'], $c['designation'], $c['department'],
                 $c['email'], $c['mobile'], $c['phone'], now3()]);
        }
        exec_sql('UPDATE leads SET status=?, mergedIntoId=?, deletedAt=?, updatedAt=? WHERE id=?',
            ['MERGED', $keepId, now3(), now3(), $mergeId]);
        exec_sql('UPDATE duplicates SET status=? WHERE leadAId=? OR leadBId=?', ['MERGED', $mergeId, $mergeId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_out('Merge failed', 500);
    }
    audit($user['sub'], 'DUPLICATE_MERGE', 'Lead', $mergeId, ['keepId' => $keepId]);
    json_out(['merged' => $mergeId, 'into' => $keepId,
              'fieldsFilled' => count($set), 'contactsMoved' => count($newContacts)]);
}
