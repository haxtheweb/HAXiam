<?php
include_once 'system/lib/IAM.php';
include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
if (isset($IAM->enterprise->userVar)) {
	// execute setting up the IAM wrapper
	setcookie('haxcms_refresh_token', $GLOBALS['HAXCMS']->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
	header("Location: " . $IAM->enterprise->iamUrl);
}
else {
	// do something to login
	header("Location: " . $IAM->enterprise->login);
}
