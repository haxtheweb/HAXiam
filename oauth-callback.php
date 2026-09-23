<?php
/**
 * oauth-callback.php — Microsoft Entra ID OAuth2/OIDC redirect URI endpoint.
 *
 * Wire-up per `_contracts/azure_json_schema.md` publisher invariant #8:
 *  - Bootstraps composer autoload + HAXcms + AzureOIDC class.
 *  - Validates the ID token (signature + iss/aud/exp) BEFORE issuing a refresh
 *    token (auth-failure path issues no refresh token at all).
 *  - On success: sets $_SESSION['HAXIAM_USER'], calls
 *    $HAXCMS->setRefreshTokenCookie($HAXCMS->getRefreshToken($user)) (mirrors
 *    the existing pattern in system/boilerplate/systemsetup/iamConfig.php),
 *    liberates the user directory if missing, and redirects to the IAM
 *    dashboard URL $IAM->enterprise->iamUrl . $user.
 *  - On failure: redirects to login.php?sso_error=<reason> WITHOUT issuing a
 *    refresh token, then exits.
 *
 * Constants:
 *  - Defines IAM_INTERNALS = 'oauth-callback' so the same dance as login.php
 *    works (bootstrapHAX.php + _iamConfig/iamConfig.php know what's up).
 *
 * Composer autoload: wad-relative to HAxiam root (../../vendor/autoload.php).
 * This file is at HAxiam root (alongside login.php) per the plan, so the
 * relative path is simply __DIR__ . '/vendor/autoload.php'.
 */
define('IAM_INTERNALS', 'oauth-callback');

// Composer autoload is optional at runtime — AzureOIDC handles a missing
// dependency defensively, returning null on calls that need the libraries.

// Use AzureOIDC::load() defensively: if the config file isn't present or
// Azure isn't enabled, just redirect to login with the same friendly hint
// that the operator would see on the dashboard.
$oauthing = true;
$oauth_provider = null;
try {
    // Composer autoload — optional.
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    include_once __DIR__ . '/system/lib/IAM.php';
    include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
    include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
    include_once $HAXCMS->configDirectory . '/config.php';

    // Defense: if azure.json does not exist the operator never configured
    // Azure — fall back to the legacy login.php redirect path.
    $azure_path = IAM_ROOT . '/_iamConfig/azure.json';
    if (!file_exists($azure_path)) {
        header('Location: login.php?sso_error=azure_not_configured');
        exit;
    }

    if (!class_exists('AzureOIDC')) {
        // composer.json exists but vendor/ wasn't installed — surface a
        // clear remediation rather than silently failing.
        header('Location: login.php?sso_error=composer_missing');
        exit;
    }

    $oauth_provider = AzureOIDC::load($azure_path);
    if (!$oauth_provider->isEnabled()) {
        header('Location: login.php?sso_error=azure_not_enabled');
        exit;
    }
} catch (Throwable $boot_err) {
    // Bootstrap failed for some reason — surface to login as an SSO error
    // without leaking credential details.
    header('Location: login.php?sso_error=bootstrap_failed');
    exit;
}

// Handle the callback. Returns a machine-name-safe username OR null.
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

// Success path: stash in session, issue refresh token, liberate, redirect.
// Mirrors iamConfig.php:104-108 / login.php:7-15 verbatim.
$_SESSION['HAXIAM_USER'] = $user;
if (method_exists($HAXCMS, 'getRefreshToken') && method_exists($HAXCMS, 'setRefreshTokenCookie')) {
    $HAXCMS->setRefreshTokenCookie($HAXCMS->getRefreshToken($user));
} else if (method_exists($HAXCMS, 'getRefreshToken')) {
    setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($user), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
}
if (!is_dir(IAM_ROOT . '/users/' . $user)) {
    $IAM->liberate($user);
}
header('Location: ' . $IAM->enterprise->iamUrl . $user);
exit;
?>
