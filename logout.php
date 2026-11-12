<?php
/**
 * logout.php - end the HAXiam session locally, then hand off to the IdP.
 *
 * Destroys the PHP session, revokes every HAXcms refresh session of the
 * signed-in user on the server, expires the session and HAXcms refresh-token
 * cookies, and redirects to the Entra ID (Azure) logout endpoint when Azure
 * OIDC is enabled, otherwise back to login.php.
 *
 * Security (SEC-SESSION): after logout no refresh token issued to this user
 * before it (on any host: <domain>, iam., oer., courses.) can mint another
 * access token. The refresh cookies are host-only, so this page never sees
 * the editor's cookie; revocation is therefore server-side and user-wide
 * (signing out here signs the user out on every device), in the user's own
 * refresh-session store that every host consults.
 */
define('IAM_INTERNALS', 'logout');
$session_name = session_name();
if (session_status() !== PHP_SESSION_ACTIVE) {
    $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $__https,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_start();
}
include_once __DIR__ . '/system/lib/IAM.php';
$__logout_user = (isset($_SESSION['HAXIAM_USER']) && IAM::isValidUserName($_SESSION['HAXIAM_USER']))
    ? (string) $_SESSION['HAXIAM_USER'] : '';
$_SESSION = array();
$params = session_get_cookie_params();
session_destroy();

if ($__logout_user !== '') {
    // Bootstrap HAXcms only after the session is gone, so iamConfig.php (which
    // it includes) finds no signed-in user and issues nothing.
    include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
    if (isset($HAXCMS) && is_object($HAXCMS) && method_exists($HAXCMS, 'revokeAllRefreshSessions')) {
        // tokens issued by login.php / oauth-callback.php on the base host
        $HAXCMS->revokeAllRefreshSessions($__logout_user);
        // tokens issued and checked under the user's space (iam./oer./courses.)
        $__user_config = IAM_ROOT . '/users/' . $__logout_user . '/_config';
        if (is_dir($__user_config)) {
            $HAXCMS->configDirectory = $__user_config;
            $HAXCMS->revokeAllRefreshSessions($__logout_user);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = array();
        session_destroy();
    }
}

// Expire the session cookie and the HAXcms refresh token cookie, both with
// the session cookie's domain and host-only (how the refresh cookie is set).
$expire = time() - 3600;
foreach (array_unique(array((string) $params['domain'], '')) as $__cookie_domain) {
    foreach (array($session_name, 'haxcms_refresh_token') as $cookie) {
        setcookie($cookie, '', array(
            'expires' => $expire,
            'path' => '/',
            'domain' => $__cookie_domain,
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
}
header('Cache-Control: no-store');

$target = 'login.php';
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
if (!class_exists('AzureOIDC') && file_exists(__DIR__ . '/system/lib/AzureOIDC.php')) {
    include_once __DIR__ . '/system/lib/AzureOIDC.php';
}
if (class_exists('AzureOIDC') && file_exists(__DIR__ . '/_iamConfig/azure.json')) {
    try {
        $azure = AzureOIDC::load(__DIR__ . '/_iamConfig/azure.json');
        if ($azure->isEnabled()) {
            $target = $azure->getLogoutUrl();
        }
    } catch (Throwable $e) {
        // fall back to the local login page
    }
}
header('Location: ' . $target);
exit;
