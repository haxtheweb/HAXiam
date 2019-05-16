<?php
// working with git operators
include_once 'Git.php';
$here = __FILE__;
define('IAM_ROOT', str_replace('/system/IAM.php', '', $here));
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
    $this->coreDir = dirname(__FILE__) . '/../cores/HAXcms';
  }
  /**
   * Replicate and establish a new HAXcms manager
   */
  public function liberate($targetDir) {
    // open HAXcms directory
    $dir = opendir($this->coreDir);
    $userDir = dirname(__FILE__) . '/../users/' . $targetDir;
    $userSitesDir = dirname(__FILE__) . '/../users_sites/' . $targetDir;
    // create a user directory
    @mkdir($userDir, 0755, TRUE);
    @mkdir($userSitesDir, 0777, TRUE);
    @mkdir($userSitesDir . '/_sites', 0777, TRUE);
    @mkdir($userSitesDir . '/_archived', 0777, TRUE);
    @mkdir($userSitesDir . '/_published', 0777, TRUE);
    // make a config directory
    @mkdir($userDir . '/_config', 0777, TRUE);
    $configDir = opendir($userDir . '/_config');
    @symlink('../../../_iamConfig/config.json', $userDir . '/_config/config.json');
    @symlink('../../../_iamConfig/.htaccess', $userDir . '/_config/.htaccess');
    @symlink('../../../_iamConfig/SALT.txt', $userDir . '/_config/SALT.txt');
    @symlink('../../../_iamConfig/my-custom-elements.js', $userDir . '/_config/my-custom-elements.js');
    @symlink('../../users_sites/' . $targetDir . '/_sites', $userDir . '/sites');
    @symlink('../../users_sites/' . $targetDir . '/_published', $userDir . '/published');
    @symlink('../../users_sites/' . $targetDir . '/_archived', $userDir . '/archived');
    @symlink('../../users_sites/' . $targetDir . '/_sites', $userDir . '/_sites');
    @symlink('../../users_sites/' . $targetDir . '/_published', $userDir . '/_published');
    @symlink('../../users_sites/' . $targetDir . '/_archived', $userDir . '/_archived');
    @copy($this->coreDir . "/_config/config.php", $userDir . "/_config/config.php");
    $basePath = "\n" . '$HAXCMS->basePath = "/users/' . $targetDir . '/";';
    @file_put_contents($userDir . '/_config/config.php', $basePath . PHP_EOL , FILE_APPEND | LOCK_EX);
    // make a sites directory so we can save into it
    @symlink('../../cores/HAXcms/' . $file, $userDir . '/' . $file);
    // link the config directories together
    @symlink('../../cores/HAXcms/' . $file, $userDir . '/' . $file);
    // see if we can make the directory to start off
    while (FALSE !== ( $file = readdir($dir)) ) {
      if ($file != '.' && $file != '..' && $file != '_config' && $file != '_archived' && $file != '_sites' && $file != '_published' && $file != 'archived' && $file != 'sites' && $file != 'published') {
        @symlink('../../cores/HAXcms/' . $file, $userDir . '/' . $file);
      }
    }
    closedir($dir);
  }
}