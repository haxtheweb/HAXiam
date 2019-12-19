<?php
// use this to inject IAM "global" configuration settings
// this fires within the config bootstrap step, meaning if something doesn't bootstrap config
// this won't be applied
include_once str_replace('_iamConfig/HAXcmsConfig.php', '', __FILE__) . '/system/lib/IAM.php';
include_once "iamConfig.php";
global $IAM;
global $HAXCMS;
// implement the event listeners throughout HAXcms so that IAM can jump in as needed
$HAXCMS->addEventListener('haxcms-login-test', array($IAM, 'loginTest'));
$HAXCMS->addEventListener('haxcms-validate-user', array($IAM, 'validateUser'));
$HAXCMS->addEventListener('haxcms-jwt-get', array($IAM, 'getJwtUser'));
$HAXCMS->addEventListener('haxcms-jwt-invalid', array($IAM, 'jwtInvalid'));
// attempt to set base path relative to IAM configuration from how script loaded
if (isset($_SERVER['SCRIPT_NAME'])) {
    $base = explode('/', $_SERVER['SCRIPT_NAME']);
    // sanity check to ensure that the base being requested is an actual user site that exists
    if (isset($base[1]) && file_exists(__DIR__ . '/../users/' . $base[1] . '/')) {
        $HAXCMS->basePath = "/" . $base[1] . '/';
    }
}
// if we have a remote user, set that as the userVar
if (isset($IAM->enterprise->userVar)) {
    $HAXCMS->user->name = $IAM->enterprise->userVar;
    // generate JWT so that front end will automatically login!!
    $HAXCMS->config->appJWTConnectionSettings->jwt = $HAXCMS->getJWT();
    $HAXCMS->config->appJWTConnectionSettings->login =  $IAM->enterprise->login;
    $HAXCMS->config->appJWTConnectionSettings->logout = $IAM->enterprise->logout;
}