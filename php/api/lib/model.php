<?php
/**
 * Lead/contact serialization — casts MySQL types to the JSON shapes the
 * frontend expects (booleans, floats, ISO-8601 UTC dates for Safari).
 */

/** MySQL DATETIME(3) (stored UTC) -> ISO-8601 "...Z" (Safari-safe). */
function to_iso($dt) {
    if (!$dt) return null;
    $t = strtotime($dt . ' UTC');
    if ($t === false) { $t = strtotime($dt); }
    if ($t === false) return null;
    // preserve milliseconds if present
    $ms = '000';
    if (preg_match('/\.(\d{1,3})/', $dt, $m)) $ms = str_pad($m[1], 3, '0');
    return gmdate('Y-m-d\TH:i:s', $t) . '.' . $ms . 'Z';
}

function serialize_contact($r) {
    return [
        'id'          => $r['id'],
        'leadId'      => $r['leadId'],
        'fullName'    => $r['fullName'],
        'designation' => $r['designation'],
        'department'  => $r['department'],
        'email'       => $r['email'],
        'mobile'      => $r['mobile'],
        'phone'       => $r['phone'],
        'isPrimary'   => (bool) $r['isPrimary'],
        'createdAt'   => to_iso($r['createdAt'] ?? null),
    ];
}

/** Fetch contacts for many leads in one query, grouped by leadId. */
function contacts_by_lead($leadIds) {
    if (!$leadIds) return [];
    $place = implode(',', array_fill(0, count($leadIds), '?'));
    $rows = q("SELECT * FROM contacts WHERE leadId IN ($place) ORDER BY isPrimary DESC, createdAt ASC", $leadIds);
    $out = [];
    foreach ($rows as $r) { $out[$r['leadId']][] = serialize_contact($r); }
    return $out;
}

/**
 * Serialize a lead row. $contacts is the already-serialized contact array.
 * $company is an optional company row.
 */
function serialize_lead($r, $contacts = [], $company = null) {
    return [
        'id'             => $r['id'],
        'cardId'         => $r['cardId'],
        'companyId'      => $r['companyId'],
        'companyName'    => $r['companyName'],
        'website'        => $r['website'],
        'email'          => $r['email'],
        'phonePrimary'   => $r['phonePrimary'],
        'phoneSecondary' => $r['phoneSecondary'],
        'address'        => $r['address'],
        'city'           => $r['city'],
        'state'          => $r['state'],
        'country'        => $r['country'],
        'postalCode'     => $r['postalCode'],
        'gstin'          => $r['gstin'],
        'industry'       => $r['industry'],
        'linkedin'       => $r['linkedin'],
        'twitter'        => $r['twitter'] ?? null,
        'facebook'       => $r['facebook'] ?? null,
        'instagram'      => $r['instagram'] ?? null,
        'youtube'        => $r['youtube'] ?? null,
        'whatsapp'       => $r['whatsapp'] ?? null,
        'qrCodeData'     => $r['qrCodeData'] ?? null,
        'notes'          => $r['notes'] ?? null,
        'status'         => $r['status'],
        'aiConfidence'   => isset($r['aiConfidence']) ? (float) $r['aiConfidence'] : 0,
        'source'         => $r['source'],
        'reviewedById'   => $r['reviewedById'] ?? null,
        'reviewedAt'     => to_iso($r['reviewedAt'] ?? null),
        'mergedIntoId'   => $r['mergedIntoId'] ?? null,
        'scannedAt'      => to_iso($r['scannedAt'] ?? null),
        'createdAt'      => to_iso($r['createdAt'] ?? null),
        'updatedAt'      => to_iso($r['updatedAt'] ?? null),
        'deletedAt'      => to_iso($r['deletedAt'] ?? null),
        'contacts'       => $contacts,
        'company'        => $company ? [
            'id' => $company['id'], 'name' => $company['name'],
            'website' => $company['website'], 'industry' => $company['industry'],
            'gstin' => $company['gstin'], 'city' => $company['city'],
            'state' => $company['state'], 'country' => $company['country'],
        ] : null,
        'card'           => null,
    ];
}

/**
 * Ownership scope for non-admins: returns [sqlFragment, params].
 * Employees only see leads whose source card was uploaded by them.
 */
function owner_scope($user, $alias = 'l') {
    if (is_admin($user)) return ['', []];
    return [
        " AND $alias.cardId IN (SELECT c.id FROM cards c JOIN uploads u ON u.id = c.uploadId WHERE u.uploadedById = ?) ",
        [$user['sub']],
    ];
}
