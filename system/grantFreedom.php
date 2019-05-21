<?php
// working with git operators
include_once 'lib/IAM.php';
include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
// if we have an enterprise user, that means they logged in via that system
if (isset($IAM->enterprise->userVar)) {
    if (!is_dir(IAM_ROOT . '/users/' . $IAM->enterprise->userVar)) {
        $IAM->liberate($IAM->enterprise->userVar);
        header("Location: /" . $IAM->enterprise->userVar);
    }
    else {
        header("Location: /" . $IAM->enterprise->userVar);
    }
}
else {
    header("Location: " . $IAM->enterprise->login);
}