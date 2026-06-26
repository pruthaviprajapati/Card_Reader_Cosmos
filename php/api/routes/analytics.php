<?php
/** Analytics: dashboard KPIs, reports, per-employee report. */

function route_analytics_dashboard() {
    require_auth(true);

    $totalFiles     = (int) q1('SELECT COUNT(*) n FROM uploads')['n'];
    $cardsDetected  = (int) q1('SELECT COUNT(*) n FROM cards')['n'];
    $cardsProcessed = (int) q1('SELECT COUNT(*) n FROM ocr_results')['n'];
    $leadsCreated   = (int) q1('SELECT COUNT(*) n FROM leads WHERE deletedAt IS NULL')['n'];
    $duplicatesFound= (int) q1("SELECT COUNT(*) n FROM duplicates WHERE status='OPEN'")['n'];
    $exportCount    = (int) q1('SELECT COUNT(*) n FROM exports')['n'];
    $approved       = (int) q1("SELECT COUNT(*) n FROM leads WHERE status='APPROVED' AND deletedAt IS NULL")['n'];

    $successRate = $cardsDetected ? round(($cardsProcessed / $cardsDetected) * 100, 1) : 0;

    $topIndustries = q("SELECT industry AS label, COUNT(*) AS count FROM companies
                        WHERE industry IS NOT NULL GROUP BY industry ORDER BY count DESC LIMIT 5");
    $topCities = q("SELECT city AS label, COUNT(*) AS count FROM leads
                    WHERE deletedAt IS NULL AND city IS NOT NULL GROUP BY city ORDER BY count DESC LIMIT 5");
    $topStates = q("SELECT state AS label, COUNT(*) AS count FROM leads
                    WHERE deletedAt IS NULL AND state IS NOT NULL GROUP BY state ORDER BY count DESC LIMIT 5");

    $intify = fn($rows) => array_map(fn($r) => ['label' => $r['label'], 'count' => (int) $r['count']], $rows);

    $recentImports = q('SELECT * FROM uploads ORDER BY createdAt DESC LIMIT 5');
    $recentExports = q('SELECT * FROM exports ORDER BY createdAt DESC LIMIT 5');
    $recentActivity = q('SELECT * FROM audit_logs ORDER BY createdAt DESC LIMIT 10');

    json_out([
        'kpis' => [
            'totalFiles' => $totalFiles, 'cardsDetected' => $cardsDetected,
            'cardsProcessed' => $cardsProcessed, 'leadsCreated' => $leadsCreated,
            'duplicatesFound' => $duplicatesFound, 'successRate' => $successRate,
            'exportCount' => $exportCount, 'approved' => $approved,
        ],
        'topIndustries' => $intify($topIndustries),
        'topCities'     => $intify($topCities),
        'topStates'     => $intify($topStates),
        'recentImports' => $recentImports,
        'recentExports' => $recentExports,
        'recentActivity'=> $recentActivity,
    ]);
}

function route_analytics_reports() {
    require_auth(true);
    $total    = (int) q1('SELECT COUNT(*) n FROM leads')['n'];
    $approved = (int) q1("SELECT COUNT(*) n FROM leads WHERE status='APPROVED'")['n'];
    $withEmail= (int) q1('SELECT COUNT(*) n FROM leads WHERE email IS NOT NULL')['n'];
    $withPhone= (int) q1('SELECT COUNT(*) n FROM leads WHERE phonePrimary IS NOT NULL')['n'];
    $withGstin= (int) q1('SELECT COUNT(*) n FROM leads WHERE gstin IS NOT NULL')['n'];
    $pct = fn($x) => $total ? round(($x / $total) * 100, 1) : 0;

    $intify = fn($rows) => array_map(fn($r) => ['label' => $r['label'], 'count' => (int) $r['count']], $rows);

    json_out([
        'leadAcquisition' => q("SELECT DATE(scannedAt) AS day, COUNT(*) AS count FROM leads
                                GROUP BY DATE(scannedAt) ORDER BY day DESC LIMIT 30"),
        'industryDistribution' => $intify(q("SELECT industry AS label, COUNT(*) count FROM companies
                                WHERE industry IS NOT NULL GROUP BY industry ORDER BY count DESC LIMIT 20")),
        'stateDistribution' => $intify(q("SELECT state AS label, COUNT(*) count FROM leads
                                WHERE state IS NOT NULL GROUP BY state ORDER BY count DESC LIMIT 20")),
        'cityDistribution' => $intify(q("SELECT city AS label, COUNT(*) count FROM leads
                                WHERE city IS NOT NULL GROUP BY city ORDER BY count DESC LIMIT 20")),
        'crmReadiness' => ['total' => $total, 'approved' => $approved, 'approvedPct' => $pct($approved)],
        'dataQuality' => ['withEmailPct' => $pct($withEmail), 'withPhonePct' => $pct($withPhone), 'withGstinPct' => $pct($withGstin)],
    ]);
}

function route_reports_employees() {
    require_role('ADMIN');
    $rows = q("SELECT u.id AS userId, u.name AS name, u.email AS email,
                      DATE_FORMAT(l.createdAt, '%Y-%m-%d') AS date, COUNT(*) AS count
               FROM leads l
               JOIN cards c   ON c.id  = l.cardId
               JOIN uploads up ON up.id = c.uploadId
               JOIN users u   ON u.id  = up.uploadedById
               WHERE l.deletedAt IS NULL
               GROUP BY u.id, u.name, u.email, DATE_FORMAT(l.createdAt, '%Y-%m-%d')
               ORDER BY date DESC, count DESC");
    json_out(array_map(fn($r) => [
        'userId' => $r['userId'], 'name' => $r['name'], 'email' => $r['email'],
        'date' => $r['date'], 'count' => (int) $r['count'],
    ], $rows));
}
