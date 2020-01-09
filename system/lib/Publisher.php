<?php
// working with git operators
include_once 'Git.php';
class Publisher {
  public $config;
  private $machine;
  public $active;
  /**
   * constructor
   */
  public function __construct() {
    $this->active = NULL;
    // load publishers file
    $this->config = json_decode(file_get_contents(__DIR__ . '/../_iamConfig/publishers.json'));
  }
  /**
   * Load a definition from key
   */
  public function load($machine) {
    $this->machine = $machine;
    $found = array_filter($this->config->publishers, function ($e) {
      return $e->machine == $this->machine;
    });
    if (count($found) === 1) {
      $this->active = array_pop($found);
      return $this->active;
    }
    else {
      return FALSE;
    }
  }
}