<?php
include_once 'system/lib/IAM.php';
include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
if (isset($IAM->enterprise->userVar)) {
	// execute setting up the IAM wrapper
	header("Location: " . $IAM->enterprise->iamUrl);
}
else {
	// do something to login
	header("Location: " . $IAM->enterprise->login);
}
