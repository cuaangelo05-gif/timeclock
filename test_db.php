<?php
require 'config.php';
try {
  $r = $pdo->query("SELECT COUNT(*) AS c FROM employees")->fetch();
  echo 'OK: employees=' . ($r['c'] ?? '0');
} catch (Exception $e) { echo 'DB ERROR: '.$e->getMessage(); }