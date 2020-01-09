<?php
// working with git operators
$publisher = new Publishers();
$publisher->load('oer');
print_r($publisher->active);