<?php
// working with git operators
include_once 'Publisher.php';
$publisher = new Publisher();
$publisher->load('oer');
print_r($publisher->active);