<?php
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/Sandbox.php';

$s = new Sandbox();

echo "=== Test 1: Simple echo ===\n";
$r = $s->run("print(input())", "hello", 2.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "=== Test 2: Wrong answer ===\n";
$r = $s->run("print(42)", "", 2.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "Compare with 'hello': " . (Sandbox::compareOutput($r['output'], 'hello') ? 'MATCH' : 'NO MATCH') . "\n\n";

echo "=== Test 3: Runtime error ===\n";
$r = $s->run("print(undefined_variable)", "", 2.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "=== Test 4: Time limit (infinite loop) ===\n";
$r = $s->run("while True: pass", "", 1.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "=== Test 5: Multiple lines output ===\n";
$r = $s->run("for i in range(3): print(i)", "", 2.0, 128);
echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "Compare with '0\\n1\\n2': " . (Sandbox::compareOutput($r['output'], "0\n1\n2") ? 'MATCH' : 'NO MATCH') . "\n\n";

echo "=== Test 6: Trailing spaces normalization ===\n";
echo "Compare 'hello  \\nworld  \\n' with 'hello\\nworld': ";
echo (Sandbox::compareOutput("hello  \nworld  \n", "hello\nworld") ? 'MATCH' : 'NO MATCH') . "\n";