<?php
declare(strict_types=1);

// Reproduce a fresh checkout: the SQLite directory has never been created.
$root = sys_get_temp_dir() . '/taxilink-bootstrap-' . bin2hex(random_bytes(8));
$directory = $root . '/nested';
$database = $directory . '/taxilink.sqlite';
$environment = array_merge(getenv(), [
    'APP_ENV' => 'test',
    'DEMO_MODE' => '1',
    'DB_DSN' => 'sqlite:' . $database,
]);

try {
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/console.php', 'seed'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动首次初始化测试');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_file($database)) {
        throw new RuntimeException("首次初始化失败：$output $errors");
    }
    $pdo = new PDO('sqlite:' . $database);
    if ((int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() !== 2) {
        throw new RuntimeException('首次初始化未写入演示企业');
    }
    $pdo = null;
    echo "✓ 缺少 SQLite 数据目录时可完成首次初始化\n";
} finally {
    foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
