<?php
define("IAM_INTERNALS", "login");
include_once 'system/lib/IAM.php';

// Surface OAuth/OIDC callback errors to the user (H2 identity_conflict + the
// existing sso_error codes produced by oauth-callback.php). Render a minimal
// message and stop BEFORE the iamConfig.php routing/redirect logic so the
// operator actually sees it instead of being bounced to the authorize flow.
if (isset($_GET['sso_error']) && is_string($_GET['sso_error']) && trim($_GET['sso_error']) !== '') {
	$__sso_err = (string)$_GET['sso_error'];
	// Surface an administrator contact email from azure.json when configured.
	$__sso_admin_email = '';
	if (!class_exists('AzureOIDC') && file_exists(IAM_ROOT . '/system/lib/AzureOIDC.php')) {
		include_once IAM_ROOT . '/system/lib/AzureOIDC.php';
	}
	if (class_exists('AzureOIDC') && file_exists(IAM_ROOT . '/_iamConfig/azure.json')) {
		try {
			$__sso_cfg = AzureOIDC::load(IAM_ROOT . '/_iamConfig/azure.json');
			$__sso_admin_email = (string)$__sso_cfg->getConfigField('adminContactEmail');
		} catch (Throwable $__sso_cfg_err) {
			$__sso_admin_email = '';
		}
	}
	unset($__sso_cfg, $__sso_cfg_err);
	$__sso_messages = array(
		'identity_conflict'    => 'This account could not be assigned a unique user space because the name is already in use by another account.',
		'azure_not_configured' => 'Single sign-on is not configured on this server.',
		'azure_not_enabled'    => 'Single sign-on is not enabled on this server.',
		'composer_missing'     => 'Single sign-on dependencies are not installed.',
		'bootstrap_failed'     => 'Single sign-on could not start.',
		'callback_failed'      => 'Single sign-in did not complete. Please try again.',
		'azure_url_failed'     => 'Single sign-on could not reach the identity provider.',
	);
	$__sso_msg = isset($__sso_messages[$__sso_err]) ? $__sso_messages[$__sso_err] : 'Single sign-on error.';
	$__sso_contact = ($__sso_admin_email !== '')
		? 'Contact your system administrator at ' . htmlspecialchars($__sso_admin_email, ENT_QUOTES, 'UTF-8') . ' for help.'
		: 'Contact your system administrator for help.';
	http_response_code(($__sso_err === 'identity_conflict') ? 403 : 503);
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign-in error</title></head><body><h1>Sign-in error</h1><p>' . htmlspecialchars($__sso_msg, ENT_QUOTES, 'UTF-8') . '</p><p>' . $__sso_contact . '</p></body></html>';
	exit;
}
unset($__sso_err, $__sso_admin_email, $__sso_messages, $__sso_msg, $__sso_contact);

include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
if (isset($IAM->enterprise->userVar)) {
	// execute setting up the IAM wrapper
	// Security (H3): set the Secure cookie flag when the request is over TLS so
	// the bearer refresh token is never sent over a plain-HTTP request (matches
	// the detection in oauth-callback.php).
	$_isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = $_isSecure, $_httponly = true);
	// verify they have a user directory
	if (!is_dir(IAM_ROOT . '/users/' . $IAM->enterprise->userVar)) {
		$IAM->liberate($IAM->enterprise->userVar);
	}
	header("Location: " . $IAM->enterprise->iamUrl . $IAM->enterprise->userVar);
}
else {
	// Azure AD / OIDC bridge (issue #3070, _contracts/azure_json_schema.md
	// invariant #7). When azure.json exists, AzureOIDC is loadable, and
	// isEnabled() is true, kick the user into the Microsoft authorize URL
	// via oauth-callback.php. Otherwise fall through to the legacy login
	// redirect target (Shibboleth / Apache module), unchanged.
	$__azure_login_redirect = false;
	if (
		file_exists(IAM_ROOT . '/_iamConfig/azure.json') &&
		class_exists('AzureOIDC')
	) {
		try {
			$__azure_login = AzureOIDC::load(IAM_ROOT . '/_iamConfig/azure.json');
			if ($__azure_login->isEnabled()) {
			$__azure_login_state = $__azure_login->generateState();
			$_SESSION['oauth_state'] = $__azure_login_state;
			// OIDC nonce (M4) + PKCE verifier (M5): stash both in the session so
			// oauth-callback.php's handleCallback() can bind the ID token to this
			// request and redeem the code with the verifier.
			$__azure_login_nonce = $__azure_login->generateNonce();
			$__azure_login_verifier = $__azure_login->generateCodeVerifier();
			$_SESSION['oauth_nonce'] = $__azure_login_nonce;
			$_SESSION['oauth_code_verifier'] = $__azure_login_verifier;
			$__azure_authorize_url = $__azure_login->buildAuthorizationUrl($__azure_login_state, $__azure_login_nonce, $__azure_login_verifier);
				if (is_string($__azure_authorize_url) && $__azure_authorize_url !== '') {
					header("Location: " . $__azure_authorize_url);
					$__azure_login_redirect = true;
				}
			}
		} catch (Throwable $__azure_login_err) {
			// Azure is enabled but URL construction failed — redirect to
			// an error page, NOT to /login.php (which would loop infinitely
			// since it would try Azure again) (review fix #6).
			header("Location: login.php?sso_error=azure_url_failed");
			exit;
		}
	}
	unset($__azure_login, $__azure_login_state, $__azure_login_nonce, $__azure_login_verifier, $__azure_authorize_url, $__azure_login_err);
	if (!$__azure_login_redirect) {
		// Only use the legacy login target when Azure is NOT enabled.
		// When Azure is enabled but returned false (e.g. not configured),
		// the enterprise->login redirect is safe.
		header("Location: " . $IAM->enterprise->login);
	}
}
