<?php
/**
 * Duplicate detection — weighted matching with blocking keys (near-linear).
 * Ported from the Node duplicateDetection service.
 */

function dup_norm_email($e) { return strtolower(trim($e ?? '')); }
function dup_norm_phone($p) { $d = preg_replace('/\D/', '', $p ?? ''); return substr($d, -10); }
function dup_norm_company($c) {
    $c = strtolower($c ?? '');
    $c = preg_replace('/\b(pvt|private|ltd|limited|llp|llc|inc|corp|co|company)\b\.?/', '', $c);
    return preg_replace('/[^a-z0-9]/', '', $c);
}

/** Normalised Levenshtein similarity 0..1 (uses PHP's built-in levenshtein). */
function dup_similarity($a, $b) {
    $a = $a ?? ''; $b = $b ?? '';
    if ($a === '' && $b === '') return 1.0;
    if ($a === '' || $b === '') return 0.0;
    $max = max(strlen($a), strlen($b));
    // levenshtein() caps inputs at 255 chars — company/city names are short.
    return 1 - (levenshtein($a, $b) / $max);
}

function dup_score_pair($a, $b) {
    $reasons = []; $score = 0;
    if (!empty($a['email']) && dup_norm_email($a['email']) === dup_norm_email($b['email'])) {
        $score = max($score, 0.95); $reasons[] = 'Identical email';
    }
    $pa = dup_norm_phone($a['phonePrimary'] ?? ''); $pb = dup_norm_phone($b['phonePrimary'] ?? '');
    if ($pa && $pa === $pb) { $score = max($score, 0.9); $reasons[] = 'Identical phone'; }

    $compSim = dup_similarity(dup_norm_company($a['companyName'] ?? ''), dup_norm_company($b['companyName'] ?? ''));
    if ($compSim > 0.85) {
        $citySim = (!empty($a['city']) && !empty($b['city']))
            ? dup_similarity(strtolower($a['city']), strtolower($b['city'])) : 0;
        $combined = 0.6 * $compSim + 0.4 * $citySim;
        if ($combined > 0.7) {
            $score = max($score, $combined);
            $reasons[] = 'Company match (' . intval($compSim * 100) . '%)';
            if ($citySim > 0.8) $reasons[] = 'Same city';
        }
    }
    return ['score' => round($score, 3), 'reasons' => $reasons];
}

/** Blocking: only compare leads that share a cheap key. */
function dup_candidate_pairs($leads) {
    $buckets = [];
    $add = function ($key, $lead) use (&$buckets) {
        if ($key === '' || $key === null) return;
        $buckets[$key][] = $lead;
    };
    foreach ($leads as $l) {
        $add('e:' . dup_norm_email($l['email'] ?? ''), $l);
        $add('p:' . dup_norm_phone($l['phonePrimary'] ?? ''), $l);
        $add('c:' . substr(dup_norm_company($l['companyName'] ?? ''), 0, 6), $l);
    }
    $seen = []; $pairs = [];
    foreach ($buckets as $group) {
        $n = count($group);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $x = $group[$i]; $y = $group[$j];
                list($a, $b) = ($x['id'] < $y['id']) ? [$x, $y] : [$y, $x];
                if ($a['id'] === $b['id']) continue;
                $key = $a['id'] . '|' . $b['id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $pairs[] = [$a, $b];
            }
        }
    }
    return $pairs;
}

/** Returns [{leadAId, leadBId, score, reasons}] above threshold. */
function dup_find($leads, $threshold = 0.7) {
    $out = [];
    foreach (dup_candidate_pairs($leads) as $pair) {
        list($a, $b) = $pair;
        $r = dup_score_pair($a, $b);
        if ($r['score'] >= $threshold) {
            $out[] = ['leadAId' => $a['id'], 'leadBId' => $b['id'], 'score' => $r['score'], 'reasons' => $r['reasons']];
        }
    }
    return $out;
}

/** Recompute duplicate pairs over active leads and upsert them. */
function dup_rescan() {
    $leads = q("SELECT id, email, phonePrimary, companyName, city FROM leads
                WHERE deletedAt IS NULL AND status IN ('PENDING_REVIEW','APPROVED')");
    $dupes = dup_find($leads);
    foreach ($dupes as $d) {
        $existing = q1('SELECT id FROM duplicates WHERE leadAId = ? AND leadBId = ?', [$d['leadAId'], $d['leadBId']]);
        if ($existing) {
            exec_sql('UPDATE duplicates SET score = ?, reasons = ? WHERE id = ?',
                [$d['score'], json_encode($d['reasons']), $existing['id']]);
        } else {
            exec_sql('INSERT INTO duplicates (id, leadAId, leadBId, score, reasons, status, createdAt)
                      VALUES (?,?,?,?,?,?,?)',
                [uuid_v4(), $d['leadAId'], $d['leadBId'], $d['score'], json_encode($d['reasons']), 'OPEN', now3()]);
        }
    }
    return count($dupes);
}
