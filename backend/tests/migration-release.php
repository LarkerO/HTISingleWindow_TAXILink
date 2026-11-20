<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use app\domain\Db;
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE orders (id TEXT PRIMARY KEY)');
$pdo->exec("INSERT INTO orders VALUES ('preserved')");
$failed = false;
try {
    Db::withConnection($pdo, fn() => Db::migrate());
} catch (Throwable $e) {
    $failed = true;
}
if (
    !$failed ||
    $pdo->query("SELECT COUNT(*) FROM orders WHERE id='preserved'")->fetchColumn() != 1 ||
    $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='system_settings'")->fetchColumn() !=
        0
) {
    throw new RuntimeException('迁移失败未完整回滚');
}
echo "✓ 失败迁移回滚 DDL 并保留已有订单数据\n";
$pdo->beginTransaction();
try {
    Db::withConnection($pdo, fn() => Db::migrate());
    throw new LogicException('应拒绝业务事务内迁移');
} catch (RuntimeException $e) {
    if ($e instanceof LogicException) {
        throw $e;
    }
}
$pdo->rollBack();
echo "✓ 拒绝在业务事务内执行迁移\n";
