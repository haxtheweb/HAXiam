<?php
// use this file to interface correctly with your enterprise setup.
// this provides paths that you commonly need to change and end points
// to ensure a smooth integration with you universal login system
global $IAM;
global $HAXCMS;
$session_data = isset($_SESSION) ? $_SESSION : NULL;
session_start();
// Restore session data.
if (!empty($session_data)) {
  $_SESSION += $session_data;
}
// support for local control of these values off file system
if (file_exists('/var/IAMCONFIG.php')) {
   include_once '/var/IAMCONFIG.php';
}
else {
  define('IAM_PROTOCOL', 'https://');
  define('IAM_BASE_DOMAIN', 'hax.WHATEVER.edu');
  define('IAM_EMPOWERED', 'iam');
  define('IAM_PRIVATE', 'courses');
  define('IAM_OPEN', 'oer');
}
$IAM->HAXcmsInit($HAXCMS);
$IAM->enterprise->iamUrl = IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/';
$IAM->enterprise->logout = 'https://login.microsoftonline.com/your_tenant_id/oauth2/v2.0/logout';
$IAM->enterprise->login = '/login.php';
// CDN so all paths resolve on front end from 1 place
if ($HAXCMS) {
  $HAXCMS->cdn = IAM_PROTOCOL . IAM_BASE_DOMAIN . '/cdn/1.x.x/';
  #$HAXCMS->cdn = "https://cdn.webcomponents.psu.edu/cdn/";
  #$HAXCMS->cdn = "https://media.aanda.psu.edu/sites/all/libraries/webcomponents/";  
}

// don't set an enterprise user if we don't have one but check our two logical locations
if (!isset($_SESSION['HAXIAM_USER']) || $_SESSION['HAXIAM_USER'] == '') {
  if (isset($_SERVER['REMOTE_USER'])) {
    $_SESSION['HAXIAM_USER'] = $_SERVER['REMOTE_USER'];
  }
  else if (isset($_SERVER['PHP_AUTH_USER'])) {
    $_SESSION['HAXIAM_USER'] = $_SERVER['PHP_AUTH_USER'];
  }
}
// find the site if it exists
// ensure that the user matches the address piece selected
$pieces = explode('/', $_SERVER['REQUEST_URI']);  
// get rid of the blank piece
array_shift($pieces);
$siteownername = array_shift($pieces);
// /sites/ we don't care about
array_shift($pieces);
// site name would be here if it exists
$sitename = array_shift($pieces);
// try to load a site
if ($sitename) {
  $site = $HAXCMS->loadSite($sitename);
}
// we have a site, and privacy setting is active, 
// and we have a host that is currently on the open domain
// force a redirect to the site for privacy settings
if (
  isset($site) &&
  isset($site->manifest->metadata->site->settings->private) && $site->manifest->metadata->site->settings->private &&
  isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_OPEN . '.' . IAM_BASE_DOMAIN
) {
  header("Location: " . IAM_PROTOCOL . IAM_PRIVATE . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
}
// we have a site, and privacy setting is NOT active, 
// and we have a host that is currently on the private domain
// force a redirect to the site for consistency reasons
else if (
  isset($site) &&
  (!isset($site->manifest->metadata->site->settings->private) || !$site->manifest->metadata->site->settings->private) &&
  isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_PRIVATE . '.' . IAM_BASE_DOMAIN
) {
  header("Location: " . IAM_PROTOCOL . IAM_OPEN . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
}
// we have a user
else if (isset($_SESSION['HAXIAM_USER']) && $_SESSION['HAXIAM_USER'] != '') {
  // we are in a site
  // and it is our site
  // and we are NOT on the IAM address
  // redirect them over to the editing address, tho this only resolves PRIVATE domain over to editing domain
  if (
    isset($site) && 
    $siteownername != '' && $_SESSION['HAXIAM_USER'] == $siteownername &&
    isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] != IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN
  ) {
      header("Location: " . IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
  }
  // we are in a site
  // and it is our site
  // and we are on the IAM address
  if (
    isset($site) && 
    $siteownername != '' && $_SESSION['HAXIAM_USER'] == $siteownername &&
    isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN
  ) {
    $IAM->enterprise->userVar = $_SESSION['HAXIAM_USER'];
    if (method_exists($HAXCMS, 'getRefreshToken')) {
      setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
    }
  }
  // we don't have a siteownername via URL, we need to redirect to the user's site space
  else if ($siteownername == '') {
      header("Location: " . IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/' . $_SESSION['HAXIAM_USER']);
  }
  // we aren't logging in
  // and names don't match
  // and we don't have a site
  // and it's on iam
  // redirect to iam for the user
  else if(
    $siteownername != 'login.php' &&
    $_SESSION['HAXIAM_USER'] != $siteownername &&
    !isset($site) &&
    isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN
  ) {
	  header("Location: " . IAM_PROTOCOL . IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN . '/' . $_SESSION['HAXIAM_USER']);
  }
  // not accessing login
  // user exists but is not current user from URL
  // but we have a site
  // has a host, but host is on iam
  // redirect to open
  // which if it is private, it will loop back to line 58, force location over to private
  // which then will NOT match this case
  else if(
    $siteownername != 'login.php' &&
    $_SESSION['HAXIAM_USER'] != $siteownername &&
    isset($site) &&
    isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN
  ) {
	  header("Location: " . IAM_PROTOCOL . IAM_OPEN . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
  }
  // we don't have a site, but we have a user name
  // this means they are going to hit the login / main cms
  else if (
    !isset($site)
  ) {
    $IAM->enterprise->userVar = $_SESSION['HAXIAM_USER'];
    if (method_exists($HAXCMS, 'getRefreshToken')) {
      setcookie('haxcms_refresh_token', $HAXCMS->getRefreshToken($IAM->enterprise->userVar), $_expires = 0, $_path = '/', $_domain = '', $_secure = false, $_httponly = true);
    }
  }
  // we do have a user and if we end up getting here it means the other tests all passed
  // bind user name to the enterprise variable
  $HAXCMS->userData->userName = $_SESSION['HAXIAM_USER'];
  $HAXCMS->userData->userPicture = '';
  // hide logout / special button
  $HAXCMS->siteListing->slot = '';
  $HAXCMS->siteListing->attr = 'hide-login hide-global-settings hide-camera';
}
// not logged in but trying to access iam based address
else if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] == IAM_EMPOWERED . '.' . IAM_BASE_DOMAIN) {
  // if we don't have a  site, the user should be redirected to login
  if (!isset($site)) {
	  header("Location: " . IAM_PROTOCOL . IAM_BASE_DOMAIN . $IAM->enterprise->login);
  }
  // if we do have a site, we should redirect to the open side of that domain
  else {
    header("Location: " . IAM_PROTOCOL . IAM_OPEN . '.' . IAM_BASE_DOMAIN . $_SERVER['REQUEST_URI']);
  }
}