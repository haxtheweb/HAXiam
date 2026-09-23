<?php
/**
 * oauth-callback.php — Microsoft Entra ID OAuth2/OIDC redirect URI endpoint.
 *
 * Wire-up per `_contracts/azure_json_schema.md` publisher invariant #8:
 *  - Validates the ID token (signature + iss/aud/exp) BEFORE any session
 *    or refresh-token logic runs (auth-failure path issues no refresh
 *    token at all).
 *  - On success: sets $_SESSION['HAXIAM_USER'], bootstraps HAXcms, calls
 *    $HAXCMS->setRefreshTokenCookie($HAXCMS->getRefreshToken($user)),
 *    liberates the user directory if missing, and redirects to the IAM
 *    dashboard URL $IAM->enterprise->iamUrl . $user.
 *  - On failure: redirects to login.php?sso_error=<reason> WITHOUT issuing a
 *    refresh token, then exits.
 *
 * CRITICAL ORDERING (review fix #15): handleCallback() validates the OAuth
 * state + ID token BEFORE iamConfig.php is included. The previous code
 * included iamConfig.php (which runs session/refresh-token routing logic)
 * before validation, so a pre-existing session with HAXIAM_USER set could
 * receive a refresh token before the callback was validated. Now the
 * callback is validated first; only on success do we set the session user
 * and bootstrap the HAXcms config for the refresh-token issuance.
 *
 * Composer autoload: relative to HAXiam root (__DIR__ . '/vendor/autoload.php').
 * The AzureOIDC class is also included explicitly so the callback works
 * even if vendor/ isn't installed yet (review fix #1).
 */
define('IAM_INTERNALS', 'oauth-callback');

// Preserve any pre-existing session data before session_start.
$session_data = isset($_SESSION) ? $_SESSION : null;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!empty($session_data)) {
    $_SESSION += $session_data;
}

// --- Phase 1: Load AzureOIDC + validate the callback (security gate) ---
// No HAXcms bootstrap, no iamConfig.php, no refresh-token logic runs
// until handleCallback() returns a valid user.
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
// Include AzureOIDC explicitly so it's available even without composer.
if (!class_exists('AzureOIDC') && file_exists(__DIR__ . '/system/lib/AzureOIDC.php')) {
    include_once __DIR__ . '/system/lib/AzureOIDC.php';
}
// IAM.php defines IAM_ROOT + HAXIAM_ACTIVE_CORE + the $IAM singleton.
include_once __DIR__ . '/system/lib/IAM.php';

$oauth_provider = null;
try {
    $azure_path = IAM_ROOT . '/_iamConfig/azure.json';
    if (!file_exists($azure_path)) {
        header('Location: login.php?sso_error=azure_not_configured');
        exit;
    }
    if (!class_exists('AzureOIDC')) {
        header('Location: login.php?sso_error=composer_missing');
        exit;
    }
    $oauth_provider = AzureOIDC::load($azure_path);
    if (!$oauth_provider->isEnabled()) {
        header('Location: login.php?sso_error=azure_not_enabled');
        exit;
    }
} catch (Throwable $boot_err) {
    header('Location: login.php?sso_error=bootstrap_failed');
    exit;
}

// Validate the callback: state CSRF check + ID token signature/claim
// verification. Returns a machine-name-safe username OR null.
$user = null;
try {
    $user = $oauth_provider->handleCallback($_GET);
} catch (Throwable $handle_err) {
    $user = null;
}

if (!is_string($user) || trim($user) === '') {
    // Failure path: NO refresh token issued (invariant #8 contract).
    header('Location: login.php?sso_error=callback_failed');
    exit;
}

// --- Phase 2: Success path — set session, bootstrap HAXcms, issue token ---
// Now that the callback is validated, set the session user and bootstrap
// the HAXcms config for refresh-token issuance + enterprise URL.
// CRITICAL: bootstrapHAX.php MUST be included BEFORE iamConfig.php because
// iamConfig.php references $HAXCMS (via $IAM->HAXcmsInit($HAXCMS) and the
// session-routing logic). Without bootstrapHAX, $HAXCMS is undefined and
// PHP 8 raises a TypeError (review fix #6).
// Regenerate the session ID to prevent session fixation — an attacker
// who learned the pre-login session ID cannot reuse it after auth
// (review fix #3).
session_regenerate_id(true);

$_SESSION['HAXIAM_USER'] = $user;

include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
include_once IAM_ROOT . '/_iamConfig/iamConfig.php';

// Issue refresh token (mirrors iamConfig.php / login.php pattern).
if (method_exists($HAXCMS, 'getRefreshToken') && method_exists($HAXCMS, 'setRefreshTokenCookie')) {
    $HAXCMS->setRefreshTokenCookie($HAXCMS->getRefreshToken($user));
} else if (method_exists($HAXCMS, 'getRefreshToken')) {
    // Set the Secure flag based on whether the request is over HTTPS
    // so the bearer token isn't sent over a subsequent HTTP request
    // (review fix #4).
    $_isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($user), $_expires = 0, $_path = '/', $_domain = '', $_secure = $_isSecure, $_httponly = true);
}

// Liberate the user directory if it doesn't exist yet.
if (!is_dir(IAM_ROOT . '/users/' . $user)) {
    $IAM->liberate($user);
}

header('Location: ' . $IAM->enterprise->iamUrl . $user);
exit;
?>
