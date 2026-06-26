<?php
/**
 * Lead validation + geo resolution.
 * Groq already returns city/state/country and derives state from the GSTIN,
 * so here we (a) treat the GSTIN state code as authoritative, (b) default
 * country to India for +91 / GSTIN, and (c) score fields into a confidence.
 */

const GST_STATE_CODES = [
    '01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab',
    '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi',
    '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
    '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
    '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
    '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
    '24' => 'Gujarat', '25' => 'Daman & Diu', '26' => 'Dadra & Nagar Haveli',
    '27' => 'Maharashtra', '28' => 'Andhra Pradesh', '29' => 'Karnataka', '30' => 'Goa',
    '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
    '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh',
    '38' => 'Ladakh', '97' => 'Other Territory', '99' => 'Centre Jurisdiction',
];

function gstin_valid($v) {
    return $v && preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $v);
}

function resolve_geo($e) {
    $city    = $e['city']    ?? null;
    $state   = $e['state']   ?? null;
    $country = $e['country'] ?? null;

    // GSTIN state code is authoritative.
    if (!empty($e['gstin']) && gstin_valid($e['gstin'])) {
        $code = substr($e['gstin'], 0, 2);
        if (isset(GST_STATE_CODES[$code])) {
            $state = GST_STATE_CODES[$code];
            $country = $country ?: 'India';
        }
    }
    // Default country to India when a +91 number is present.
    if (!$country && !empty($e['phonePrimary']) && strpos($e['phonePrimary'], '+91') !== false) {
        $country = 'India';
    }
    return ['city' => $city ?: null, 'state' => $state ?: null, 'country' => $country ?: null];
}

function score_email($v) {
    if (!$v) return ['value' => null, 'valid' => false, 'confidence' => 0];
    $valid = (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $v);
    $generic = (bool) preg_match('/^(info|sales|contact|admin|office|support|hello|enquiry|inquiry)@/i', $v);
    return ['value' => $v, 'valid' => $valid, 'confidence' => $valid ? ($generic ? 0.7 : 0.99) : 0.2];
}

function score_phone($v) {
    if (!$v) return ['value' => null, 'valid' => false, 'confidence' => 0];
    $digits = preg_replace('/\D/', '', $v);
    $valid = strlen($digits) >= 8 && strlen($digits) <= 15;
    return ['value' => $v, 'valid' => $valid, 'confidence' => $valid ? 0.95 : 0.3];
}

/** Returns ['fields'=>..., 'geo'=>..., 'overallConfidence'=>float]. */
function validate_lead($e) {
    $fields = [
        'email'        => score_email($e['email'] ?? null),
        'phonePrimary' => score_phone($e['phonePrimary'] ?? null),
        'gstin'        => ['value' => $e['gstin'] ?? null, 'valid' => gstin_valid($e['gstin'] ?? null),
                           'confidence' => gstin_valid($e['gstin'] ?? null) ? 0.98 : ($e['gstin'] ?? null ? 0.25 : 0)],
    ];
    $geo = resolve_geo($e);

    $weights = ['email' => 2, 'phonePrimary' => 2, 'gstin' => 1.5];
    $num = 0; $den = 0;
    foreach ($weights as $k => $w) {
        if (($fields[$k]['value'] ?? null) !== null) { $num += $fields[$k]['confidence'] * $w; $den += $w; }
    }
    $hasCompany = !empty($e['companyName']);
    $hasName = false;
    foreach (($e['contacts'] ?? []) as $c) { if (!empty($c['fullName'])) { $hasName = true; break; } }
    $base = ($hasCompany ? 0.1 : 0) + ($hasName ? 0.1 : 0);
    $overall = min(1, $base + ($den ? ($num / $den) * 0.8 : 0));

    return ['fields' => $fields, 'geo' => $geo, 'overallConfidence' => round($overall, 4)];
}
