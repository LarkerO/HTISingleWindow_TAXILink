<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
use app\domain\{Db, Demo, Env, Install};
Env::boot();
switch ($argv[1] ?? '') {
    case 'install-code':
        try {
            echo Install::issueCode() . PHP_EOL;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
        break;
    case 'migrate':
        if (Env::installMode()) {
            fwrite(STDERR, "请通过 /install 安装，或先配置 backend/.env\n");
            exit(1);
        }
        Db::migrate();
        echo "数据库结构已更新\n";
        break;
    case 'seed':
        if (!Env::demo()) {
            throw new RuntimeException('仅演示模式允许加载演示数据');
        }
        Db::migrate();
        Demo::seed();
        echo "演示数据已写入\n";
        break;
    default:
        echo "php bin/console.php migrate | seed | install-code\n";
}
