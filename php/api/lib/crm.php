<?php
/**
 * Cosmos CRM CSV mapping — exact column order the CRM import expects.
 * Multi-contact cards expand into one row per person.
 */

const COSMOS_COLUMNS = [
    'Company Name', 'SAP Customer Code', 'Website', 'Email', 'Phone',
    'Account Status', 'Customer Type', 'Industry', 'Sales Branch', 'Rating',
    'City', 'State', 'GSTIN', 'Assigned To', 'Created At', 'Updated At',
    'Contact Person', 'Designation', 'Mobile', 'Address', 'Country',
    'Postal Code', 'LinkedIn',
];

const CRM_DEFAULTS = [
    'accountStatus'   => 'New Lead',
    'customerType'    => 'Prospect',
    'rating'          => '',
    'salesBranch'     => '',
    'sapCustomerCode' => '',
    'assignedTo'      => '',
];

function crm_fmt_dt($dt) {
    if (!$dt) return '';
    $t = strtotime($dt);
    if ($t === false) return '';
    return gmdate('Y-m-d H:i:s', $t);
}

/** Map one lead (+ its primary contact) to the Cosmos column set. */
function crm_map_lead($lead, $contacts) {
    $d = CRM_DEFAULTS;
    $primary = [];
    foreach ($contacts as $c) { if (!empty($c['isPrimary'])) { $primary = $c; break; } }
    if (!$primary && count($contacts)) $primary = $contacts[0];

    return [
        'Company Name'      => $lead['companyName'] ?: '',
        'SAP Customer Code' => $d['sapCustomerCode'],
        'Website'           => $lead['website'] ?: '',
        'Email'             => $lead['email'] ?: ($primary['email'] ?? ''),
        'Phone'             => $lead['phonePrimary'] ?: ($primary['phone'] ?? ''),
        'Account Status'    => $d['accountStatus'],
        'Customer Type'     => $d['customerType'],
        'Industry'          => $lead['industry'] ?: '',
        'Sales Branch'      => $d['salesBranch'],
        'Rating'            => $d['rating'],
        'City'              => $lead['city'] ?: '',
        'State'             => $lead['state'] ?: '',
        'GSTIN'             => $lead['gstin'] ?: '',
        'Assigned To'       => $d['assignedTo'],
        'Created At'        => crm_fmt_dt($lead['createdAt'] ?? null),
        'Updated At'        => crm_fmt_dt($lead['updatedAt'] ?? null),
        'Contact Person'    => $primary['fullName'] ?? '',
        'Designation'       => $primary['designation'] ?? '',
        'Mobile'            => ($primary['mobile'] ?? '') ?: ($lead['phoneSecondary'] ?? ''),
        'Address'           => $lead['address'] ?: '',
        'Country'           => $lead['country'] ?: '',
        'Postal Code'       => $lead['postalCode'] ?: '',
        'LinkedIn'          => $lead['linkedin'] ?: '',
    ];
}

/** Extra (non-primary) contacts become their own rows. */
function crm_extra_rows($lead, $contacts) {
    $rows = [];
    foreach ($contacts as $c) {
        if (!empty($c['isPrimary'])) continue;
        $base = crm_map_lead($lead, $contacts);
        $base['Email']          = $c['email'] ?? '';
        $base['Phone']          = $c['phone'] ?? '';
        $base['Contact Person'] = $c['fullName'] ?? '';
        $base['Designation']    = $c['designation'] ?? '';
        $base['Mobile']         = $c['mobile'] ?? '';
        $rows[] = $base;
    }
    return $rows;
}
