<?php
// use this file to interface correctly with your enterprise setup.
// this provides paths that you commonly need to change and end points
// to ensure a smooth integration with you universal login system
global $IAM;
global $HAXCMS;
// pass over the session data from before
$session_data = isset($_SESSION) ? $_SESSION : NULL;
// this wipes session but keeps it going
session_start();
// Restore session data.
if (!empty($session_data)) {
  $_SESSION += $session_data;
}
// set the defined defaults
define('IAM_PROTOCOL', 'https://');
// replace this with your institution / base
define('IAM_BASE_DOMAIN', 'hax.WHATEVER.edu');
define('IAM_EMPOWERED', 'iam');
define('IAM_OPEN', 'oer');
// boot up HAXcms
$IAM->HAXcmsInit($HAXCMS);
// URL for pointing to the authenitcated / HAXcms space
$IAM->enterprise->iamUrl = IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/';
$IAM->enterprise->logout = 'https://ENTERPRISELOGOUT.edu/logout?' . IAM_PROTOCOL . IAM_BASE_DOMAIN . '/';
$IAM->enterprise->login = '/login.php';
// CDN so all paths resolve on front end from 1 place
$HAXCMS->cdn = IAM_PROTOCOL . IAM_BASE_DOMAIN . '/cdn/1.x.x/';
#$HAXCMS->cdn = "https://cdn.webcomponents.psu.edu/cdn/";
// don't set an enterprise user if we don't have one
if (!isset($_SESSION['HAXIAM_USER']) || $_SESSION['HAXIAM_USER'] == '') {
  if (isset($_SERVER['REMOTE_USER'])) {
    $_SESSION['HAXIAM_USER'] = $_SERVER['REMOTE_USER'];
  }
  else if (isset($_SERVER['PHP_AUTH_USER'])) {
    $_SESSION['HAXIAM_USER'] = $_SERVER['PHP_AUTH_USER'];
  }
}
if (isset($_SESSION['HAXIAM_USER']) && $_SESSION['HAXIAM_USER'] != '') {
  $IAM->enterprise->userVar = $_SESSION['HAXIAM_USER'];
  if (method_exists($HAXCMS, 'getRefreshToken')) {
    setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
  }
  // ensure that the user matches the address piece selected
  $pieces = explode('/', $_SERVER['REQUEST_URI']);  
  array_shift($pieces);
  if ($pieces[0] == '') {
      header("Location: " . IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/' . $_SESSION['HAXIAM_USER']);
  }
  else if($pieces[0] != 'login.php' && $IAM->enterprise->userVar != $pieces[0]) {
	  header("Location: " . IAM_PROTOCOL . IAM_OPEN . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
  }
  // bind user name to the enterprise variable
  $HAXCMS->userData->userName = $IAM->enterprise->userVar;
  $HAXCMS->userData->userPicture = '';
  // hide logout / special button
  $HAXCMS->siteListing->slot = '';
  $HAXCMS->siteListing->attr = 'hide-login hide-global-settings hide-camera';
}
// not logged in but trying to access iam based address
else if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN) {
	header("Location: " . IAM_PROTOCOL . IAM_BASE_DOMAIN . $IAM->enterprise->login);
}
