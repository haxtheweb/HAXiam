<?php
define("IAM_INTERNALS", "login");
include_once 'system/lib/IAM.php';
include_once IAM_ROOT . '/cores/' . HAXIAM_ACTIVE_CORE . '/system/backend/php/bootstrapHAX.php';
include_once $HAXCMS->configDirectory . '/config.php';
if (isset($IAM->enterprise->userVar)) {
	// execute setting up the IAM wrapper
	setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
	header("Location: " . $IAM->enterprise->iamUrl . $IAM->enterprise->userVar);
}
else {
	// do something to login
	header("Location: " . $IAM->enterprise->login);
}
