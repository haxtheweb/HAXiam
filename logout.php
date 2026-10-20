<?php
/**
 * logout.php - end the HAXiam session locally, then hand off to the IdP.
 *
 * Destroys the PHP session, expires the session and HAXcms refresh-token
 * cookies, and redirects to the Entra ID (Azure) logout endpoint when Azure
 * OIDC is enabled, otherwise back to login.php.
 */
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
$_SESSION = array();
$params = session_get_cookie_params();
session_destroy();

// Expire the session cookie and the HAXcms refresh token cookie.
$expire = time() - 3600;
foreach (array($session_name, 'haxcms_refresh_token') as $cookie) {
    setcookie($cookie, '', array(
        'expires' => $expire,
        'path' => '/',
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ));
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
