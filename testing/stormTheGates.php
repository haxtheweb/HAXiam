<?php
// working with git operators
//include_once '../system/lib/IAM.php';
//include_once IAM_ROOT . '/_iamConfig/iamConfig.php';
/*if (isset($IAM->enterprise->userVar)) {
  $start = time();
  $troops = 100;
  $i = 0;
  while ($i < $troops) {
    $user = substr(md5(microtime()), rand(0,99), 8);
    // make sure it doesn't exist
    if (!is_dir(IAM_ROOT . '/users/' . $user)) {
      $IAM->liberate($user);
    }
    $i++;
  }
  $end = time();
  print 'it took ' . ($end - $start) . ' seconds to generate ' . $troops . ' HAXcms deployments';
  exit;
}*/