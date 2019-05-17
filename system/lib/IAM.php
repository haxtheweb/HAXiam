<?php
// working with git operators
define('IAM_ROOT', str_replace('/system/lib/IAM.php', '', __FILE__));
define('HAXIAM_ACTIVE_CORE', 'HAXcms-1.x.x');
class IAM {
  public $name;
  public $domain;
  public $protocol;
  public $basePath;
  public $coresDir;
  public $enterprise;
  public $publishers;
  /**
   * constructor
   */
  public function __construct() {
    // Get HTTP/HTTPS (the possible values for this vary from server to server)
    $this->protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && !in_array(strtolower($_SERVER['HTTPS']),array('off','no'))) ? 'https' : 'http';
    $this->domain = $_SERVER['HTTP_HOST'];
    // auto generate base path
    $this->basePath = '/';
    $this->coresDir = IAM_ROOT . '/cores';
    $this->enterprise = new stdClass();
  }
  /**
   * Replicate and establish a new HAXcms manager
   */
  public function liberate($targetDir, $core = HAXIAM_ACTIVE_CORE) {
    // open HAXcms directory
    $dir = opendir($this->coresDir . '/' . $core);
    $userDir = IAM_ROOT . '/users/' . $targetDir;
    $userSitesDir = IAM_ROOT . '/users_sites/' . $targetDir;
    // create a user directory
    @mkdir($userDir, 0755, TRUE);
    @mkdir($userSitesDir, 0777, TRUE);
    // dev ops names
    @mkdir($userSitesDir . '/_sites', 0777, TRUE);
    @mkdir($userSitesDir . '/_archived', 0777, TRUE);
    @mkdir($userSitesDir . '/_published', 0777, TRUE);
    // nicer name symlinks
    @symlink($userSitesDir . '/_sites', $userSitesDir . '/sites');
    @symlink($userSitesDir . '/_archived', $userSitesDir . '/archived');
    @symlink($userSitesDir . '/_published', $userSitesDir . '/published');
    // This has to exist for all the relative paths we're using to correctly resolve
    @symlink('../../cores/' . $core . '/build', $userSitesDir . '/build');
    // make a config directory
    @mkdir($userDir . '/_config', 0777, TRUE);
    @symlink('../../../_iamConfig/config.json', $userDir . '/_config/config.json');
    @symlink('../../../_iamConfig/.htaccess', $userDir . '/_config/.htaccess');
    @symlink('../../../_iamConfig/SALT.txt', $userDir . '/_config/SALT.txt');
    @symlink('../../../_iamConfig/my-custom-elements.js', $userDir . '/_config/my-custom-elements.js');
    @symlink('../../../_iamConfig/HAXcmsConfig.php', $userDir . '/_config/config.php');
    // make links between user sites and user directory
    // these must be relative
    @symlink('../../users_sites/' . $targetDir . '/_sites', $userDir . '/sites');
    @symlink('../../users_sites/' . $targetDir . '/_published', $userDir . '/published');
    @symlink('../../users_sites/' . $targetDir . '/_archived', $userDir . '/archived');
    @symlink('../../users_sites/' . $targetDir . '/_sites', $userDir . '/_sites');
    @symlink('../../users_sites/' . $targetDir . '/_published', $userDir . '/_published');
    @symlink('../../users_sites/' . $targetDir . '/_archived', $userDir . '/_archived');
    // make a sites directory so we can save into it
    @symlink('../../cores/' . $core .'/' . $file, $userDir . '/' . $file);
    // link the config directories together
    @symlink('../../cores/' . $core . '/' . $file, $userDir . '/' . $file);
    // see if we can make the directory to start off
    while (FALSE !== ( $file = readdir($dir)) ) {
      if ($file != '.' && $file != '..' && $file != '_config' && $file != '_archived' && $file != '_sites' && $file != '_published' && $file != 'archived' && $file != 'sites' && $file != 'published') {
        @symlink('../../cores/' . $core . '/' . $file, $userDir . '/' . $file);
      }
    }
    closedir($dir);
  }
  /**
   * Callback for event: haxcms-init
   * Init event for the entire HAXcms environment
   */
  public function HAXcmsInit(&$hax) {
    // load in core publishing data
    if (file_exists(IAM_ROOT . '/_iamConfig/publishing.json')) {
      $publishingData = json_decode(
          file_get_contents(
              IAM_ROOT . '/_iamConfig/publishing.json'
          )
      );
      foreach ($publishingData as $name => $data) {
        $hax->config->publishing->{$name} = $data;
      }
    }
  }
  /**
   * Callback for event: haxcms-login-test
   * Respond to the event firing to test login
   */
  public function loginTest(&$usr) {
    // if we match the enterprise login to the user name in question we're good
    if ($this->enterprise->userVar == $usr->name) {
      $usr->grantAccess = true;
    }
  }
  /**
   * Callback for event: haxcms-validate-user
   * Validate that this user name matches the current one
   */
  public function validateUser(&$usr) {
    if ($this->enterprise->userVar == $usr->name) {
      $usr->grantAccess = true;
    }
  }
  /**
   * Callback for event: haxcms-jwt-get
   */
  public function getJwtUser(&$token) {
    if ($this->enterprise->userVar) {
      $token['user'] = $this->enterprise->userVar;
    }
  }
}
// weird but IAM self-invokes as there can only ever be one enterprise in a session
global $IAM;
$IAM = new IAM();