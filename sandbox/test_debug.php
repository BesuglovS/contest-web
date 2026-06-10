<?php
require_once __DIR__ . '/../config.php';

// Test Python directly
echo "=== findPython test ===\n";
$test = shell_exec('python --version 2>&1');
echo "Result: " . var_export($test, true) . "\n";

// Test with a simple script
$tmpFile = SANDBOX_DIR . '/debug_test.py';
file_put_contents($tmpFile, "print('HELLO_FROM_PYTHON')");
$cmd = 'python ' . escapeshellarg($tmpFile) . ' 2>&1';
echo "CMD: $cmd\n";
$out = shell_exec($cmd);
echo "OUTPUT: " . var_export($out, true) . "\n";
@unlink($tmpFile);

// Now test the wrapper
require_once BASE_PATH . '/includes/Sandbox.php';
$s = new Sandbox();
$r = $s->run("print('hello')", "", 2.0, 128);
echo "\n=== Sandbox result ===\n";
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

// Check what files exist
echo "\n=== Files in sandbox ===\n";
$files = glob(SANDBOX_DIR . '/run_*');
foreach ($files as $f) {
    echo basename($f) . " (" . filesize($f) . " bytes)\n";
}