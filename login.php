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
	// do something to login
	header("Location: " . $IAM->enterprise->login);
}