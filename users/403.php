<?php
  header('Content-Type: application/json; charset=utf-8');
  header('Status: 403');
  print '{"status": 403, "data": "Invalid token"}';