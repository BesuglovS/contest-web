<?php
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/Sandbox.php';

$s = new Sandbox();
$r = $s->run("print(input())", "hello", 2.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);