<?php
/**
 * Nightly CI only: mock Entra ID authorize endpoint (idp.haxiam.test).
 *
 * Stands in for the account picker: the test client says who signs in with
 * ?as=<upn> (and optionally &oid=<object id>). The endpoint enforces the same
 * things Entra does for HAXiam's request (registered client + exact redirect
 * URI, state/nonce present, S256 PKCE challenge), mints an RS256 ID token and
 * redirects back to the redirect URI with a one-shot code, like a real IdP.
 *
 * ?mode= lets the nightly prove HAXiam rejects bad tokens:
 *   forged      signed with a key that is not in the published key set
 *   wrongnonce  nonce claim does not match the login request
 *   wrongtenant tid claim names another tenant
 *   expired     exp in the past
 */
$state = getenv('HAXIAM_MOCK_IDP_STATE') ?: '/var/lib/haxiam-mockidp';
$cfg = json_decode((string) @file_get_contents($state . '/idp.json'), true);
header('Cache-Control: no-store');
function idp_fail($code, $msg)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n";
    exit;
}
function b64url($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
if (!is_array($cfg)) {
    idp_fail(500, 'mock IdP not initialised');
}
$q = $_GET;
foreach (array('client_id', 'redirect_uri', 'state', 'nonce', 'response_type', 'code_challenge', 'code_challenge_method', 'as') as $k) {
    if (!isset($q[$k]) || !is_string($q[$k]) || $q[$k] === '') {
        idp_fail(400, 'missing ' . $k);
    }
}
if ($q['client_id'] !== $cfg['clientId']) {
    idp_fail(400, 'unknown client_id');
}
if ($q['redirect_uri'] !== $cfg['redirectUri']) {
    idp_fail(400, 'redirect_uri does not match the registered value');
}
if ($q['response_type'] !== 'code' || $q['code_challenge_method'] !== 'S256') {
    idp_fail(400, 'expected response_type=code and S256 PKCE');
}
$mode = isset($q['mode']) && is_string($q['mode']) ? $q['mode'] : '';
$now = time();
$tenant = $cfg['tenantId'];
$claims = array(
    'iss' => 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/v2.0',
    'aud' => $cfg['clientId'],
    'tid' => ($mode === 'wrongtenant') ? '99999999-9999-9999-9999-999999999999' : $tenant,
    'iat' => $now,
    'nbf' => $now,
    'exp' => ($mode === 'expired') ? $now - 3600 : $now + 3600,
    'nonce' => ($mode === 'wrongnonce') ? 'not-the-nonce' : $q['nonce'],
    'preferred_username' => $q['as'],
    'oid' => (isset($q['oid']) && is_string($q['oid']) && $q['oid'] !== '') ? $q['oid'] : hash('sha256', $q['as']),
    'name' => 'Nightly ' . $q['as'],
);
$header = array('alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'haxiam-nightly');
$signingInput = b64url(json_encode($header)) . '.' . b64url(json_encode($claims));
$keyFile = $state . '/' . (($mode === 'forged') ? 'rogue.pem' : 'signing.pem');
$key = openssl_pkey_get_private((string) @file_get_contents($keyFile));
if ($key === false || !openssl_sign($signingInput, $sig, $key, OPENSSL_ALGO_SHA256)) {
    idp_fail(500, 'signing failed');
}
$code = bin2hex(random_bytes(16));
file_put_contents($state . '/codes/' . $code . '.json', json_encode(array(
    'id_token' => $signingInput . '.' . b64url($sig),
)), LOCK_EX);
$sep = (strpos($q['redirect_uri'], '?') === false) ? '?' : '&';
header('Location: ' . $q['redirect_uri'] . $sep . http_build_query(array('code' => $code, 'state' => $q['state'])), true, 302);
