<?php
/**
 * Minimal JWT (HS256) — sign + verify. No external dependency.
 * Mirrors the Node backend's token payload: { sub, email, role, name, exp }.
 */

function b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_sign($payload, $secret, $expiresSec) {
    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresSec;

    $h = b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', "$h.$p", $secret, true);
    return "$h.$p." . b64url_encode($sig);
}

/** Returns the decoded payload array, or null if invalid/expired. */
function jwt_verify($token, $secret) {
    if (!$token || substr_count($token, '.') !== 2) return null;
    list($h, $p, $s) = explode('.', $token);

    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));
    // constant-time comparison
    if (!hash_equals($expected, $s)) return null;

    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && time() >= $payload['exp']) return null;
    return $payload;
}
