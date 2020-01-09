<?php
// use this file to interface correctly with your enterprise setup.
// this provides paths that you commonly need to change and end points
// to ensure a smooth integration with you universal login system
global $IAM;
global $HAXCMS;
define('IAM_PROTOCOL', 'https://');
define('IAM_BASE_DOMAIN', 'hax.WHATEVER.edu');
define('IAM_EMPOWERED', 'iam');
$IAM->HAXcmsInit($HAXCMS);
$IAM->enterprise->iamUrl = IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/';
$IAM->enterprise->logout = 'https://ENTERPRISELOGOUT.edu/logout?' . IAM_PROTOCOL . IAM_BASE_DOMAIN . '/';
$IAM->enterprise->login = '/login.php?redirect_url=/login.php';
// don't set an enterprise user if we don't have one
if (isset($_SERVER['REMOTE_USER'])) {
  $IAM->enterprise->userVar = $_SERVER['REMOTE_USER'];
  // ensure that the user matches the address piece selected
  $pieces = explode('/', $_SERVER['REQUEST_URI']);
  array_shift($pieces);
  if ($IAM->enterprise->userVar != $pieces[0]) {
    header("Location: /");
  }
  // hide logout / special button
  $HAXCMS->siteListing->slot = '';
  $HAXCMS->siteListing->attr = 'hide-login hide-global-settings hide-camera';
}