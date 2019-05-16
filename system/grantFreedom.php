<?php
// working with git operators
// @todo very specific to web access currently
include_once 'IAM.php';
if (isset($_SERVER['REMOTE_USER'])) {
    if (!is_dir(IAM_ROOT . '/users/' . $_SERVER['REMOTE_USER'])) {
        $iam = new IAM();
        $iam->liberate($_SERVER['REMOTE_USER']);
        header("Location: /users/" . $_SERVER['REMOTE_USER']);
    }
    else {
        header("Location: /users/" . $_SERVER['REMOTE_USER']);
    }
}
else {
  header("Location: /cosign?redirect_url=/system/grantFreedom.php");
}