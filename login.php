<?php
define("IAM_INTERNALS", "login");
include_once 'system/lib/IAM.php';
include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
if (isset($IAM->enterprise->userVar)) {
	// execute setting up the IAM wrapper
	setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
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
				$__azure_authorize_url = $__azure_login->buildAuthorizationUrl($__azure_login_state);
				if (is_string($__azure_authorize_url) && $__azure_authorize_url !== '') {
					header("Location: " . $__azure_authorize_url);
					$__azure_login_redirect = true;
				}
			}
		} catch (Throwable $__azure_login_err) {
			// Fail closed: legacy redirect below.
			$__azure_login_redirect = false;
		}
	}
	unset($__azure_login, $__azure_login_state, $__azure_authorize_url, $__azure_login_err);
	if (!$__azure_login_redirect) {
		header("Location: " . $IAM->enterprise->login);
	}
}
