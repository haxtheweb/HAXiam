<?php
// working with git operators
include_once 'Git.php';
class IAM {
  public $name;
  public $domain;
  public $protocol;
  public $basePath;
  public $coreDir;
  /**
   * constructor
   */
  public function __construct() {
    // Get HTTP/HTTPS (the possible values for this vary from server to server)
    $this->protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && !in_array(strtolower($_SERVER['HTTPS']),array('off','no'))) ? 'https' : 'http';
    $this->domain = $_SERVER['HTTP_HOST'];
    // auto generate base path
    $this->basePath = '/';
    $this->coreDir = dirname(__FILE__) . '/../cores/haxcms';
  }
  /**
   * Replicate and establish a new HAXcms manager
   */
  public function liberate($targetDir) {
    // open haxcms directory
    $dir = opendir($this->coreDir);
    $userDir = dirname(__FILE__) . '/../users/' . $targetDir;
    $userSitesDir = dirname(__FILE__) . '/../users_sites/' . $targetDir;
    // create a user directory
    @mkdir($userDir, 0755, TRUE);
    @mkdir($userSitesDir, 0777, TRUE);
    @mkdir($userSitesDir . '/_sites', 0777, TRUE);
    // make a config directory
    @mkdir($userDir . '/_config', 0777, TRUE);
    $configDir = opendir($userDir . '/_config');
    @symlink('../../../cores/haxcms/_config/config.json', $userDir . '/_config/config.json');
    @symlink('../../../cores/haxcms/_config/.htaccess', $userDir . '/_config/.htaccess');
    @symlink('../../../cores/haxcms/_config/SALT.txt', $userDir . '/_config/SALT.txt');
    @symlink('../../../cores/haxcms/_config/my-custom-elements.js', $userDir . '/_config/my-custom-elements.js');
    @symlink('../../users_sites/' . $targetDir . '/_sites', $userDir . '/_sites');
    @copy($this->coreDir . "/_config/config.php", $userDir . "/_config/config.php");
    $basePath = "\n" . '$HAXCMS->basePath = "/users/' . $targetDir . '/";';
    @file_put_contents($userDir . '/_config/config.php', $basePath . PHP_EOL , FILE_APPEND | LOCK_EX);
    // make a sites directory so we can save into it
    @symlink('../../cores/haxcms/' . $file, $userDir . '/' . $file);
    // copy sites.json boilerplate
    @copy($this->coreDir . "/system/boilerplate/systemsetup/sites.json", $userDir . "/_sites/sites.json");
    // link the config directories together
    @symlink('../../cores/haxcms/' . $file, $userDir . '/' . $file);
    // see if we can make the directory to start off
    while (FALSE !== ( $file = readdir($dir)) ) {
      if ($file != '.' && $file != '..' && $file != '_config' && $file != '_sites') {
        @symlink('../../cores/haxcms/' . $file, $userDir . '/' . $file);
      }
    }
    closedir($dir);
  }
}