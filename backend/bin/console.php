<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
use app\domain\{Db, Demo, Env};
Env::boot();
switch ($argv[1] ?? '') {
    case 'migrate':
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
        echo "php bin/console.php migrate | seed\n";
}
