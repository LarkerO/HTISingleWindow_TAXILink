# 1Panel / OpenResty 部署

适用于一台 Linux 主机：1Panel 管理两个静态站点、HTTPS 和证书，Compose 运行单实例 webman 后端。后端是常驻 CLI 进程，不使用 PHP-FPM。游戏服和 BlueMap 可以在其他主机。

## 1. 准备源码与镜像

将主平台仓库部署到 `/srv/taxilink`，安装 Docker Compose v2。以下命令均在项目根目录执行。Compose 使用相对构建路径，在 1Panel 导入编排时须保留完整源码目录结构；也可以直接在服务器终端操作。

```bash
cd /srv/taxilink
cp deploy/.env.example deploy/.env
chmod 600 deploy/.env
docker network create taxilink-ingress
docker compose --env-file deploy/.env -f deploy/compose.yml build --pull
```

网络已存在时无需再次创建。修改网络名时同步修改 `deploy/.env`。镜像不包含本机 `.env`、installation、runtime 或私钥；PHP 8.4、PDO MySQL/SQLite、curl、mbstring、pcntl、posix 和 OpenSSL CLI 在镜像中安装。进程以 www-data 运行，新建命名卷继承镜像目录权限。复用旧卷时须确认 UID/GID 与 www-data（33:33）一致。

## 2. 选择数据库方式并启动

**复用 1Panel MySQL、Redis：** 将已有数据库容器接入 Compose 的 `taxilink_default` 网络（后端首次启动后创建），或者使用后端实际可访问的私网地址。通过 1Panel 持久保存网络配置，避免重建后丢失。数据库账号须允许来自后端容器的连接，不能仅授权 `127.0.0.1`。

```bash
docker compose --env-file deploy/.env -f deploy/compose.yml up -d
```

**独立数据库：** 先在 `deploy/.env` 设置不同的 `MYSQL_PASSWORD`、`MYSQL_ROOT_PASSWORD`，再运行：

```bash
docker compose --env-file deploy/.env -f deploy/compose.yml -f deploy/compose.infra.yml config --quiet
docker compose --env-file deploy/.env -f deploy/compose.yml -f deploy/compose.infra.yml up -d
```

选择独立数据库后，后续所有 Compose 操作均保留这两个 `-f`。安装向导填写 MySQL 主机 `mysql`、端口 `3306`、库名和用户名 `taxilink`，密码对应 `MYSQL_PASSWORD`；Redis URL 填 `tcp://redis:6379`。两者不发布宿主机端口。首次创建库后，修改环境变量不会自动修改数据库已有密码。

`deploy/.env` 只用于 Compose 参数，不给后端注入 `DB_DSN`，从而保留浏览器安装向导。后端业务配置与生成的 SM2 密钥写入 installation 卷。不要把开发环境的 `backend/.env` 挂入新实例。

## 3. OpenResty 网络与静态站点

在 1Panel 中创建乘客站 `taxi.example.com` 和管理站 `taxi-admin.example.com`，使用不同主机名，申请证书并启用 HTTPS。构建前端：

```bash
npm ci
npm run build
```

分别将 `passenger/dist/`、`admin/dist/` **里面的内容**发布到两个站点根目录。仅发布静态产物，不将 backend 或整个仓库置于网站根目录。站点路径以 OpenResty 容器内可见路径为准。

新乘客端 `v2PassengerFrontend` 使用其 HBuilderX 的 H5 发行产物替换乘客站文件，保留相同代理和 SPA 回退；本仓库的 npm 构建只包含原乘客端和管理端。Android/iOS 等原生 App 单独打包，在其配置中填写实际 HTTPS 入口。

先检查 OpenResty 网络模式：

```bash
docker inspect --format '{{.HostConfig.NetworkMode}}' <OpenResty容器名>
```

- **桥接模式：** 在 1Panel 中将 OpenResty 接入 `taxilink-ingress` 并持久保存配置。临时验证可执行 `docker network connect taxilink-ingress <OpenResty容器名>`。复制 `deploy/openresty.locations.conf.example` 到站点的 server 块中，按注释删改。Docker DNS 通过 `taxilink-backend` 解析后端，不需要发布任何后端端口。
- **host 模式：** 不使用 Docker DNS。给 Compose 的 backend 增加 `ports`：`127.0.0.1:8787:8787`、`127.0.0.1:8788:8788`、`127.0.0.1:8790:8790`。站点片段删除 `resolver`，将 `set $taxilink_backend taxilink-backend;` 改为 `set $taxilink_backend 127.0.0.1;`。修改编排后重新执行 up。8789 是内部 RPC，始终不发布。

不要在桥接模式下把代理写成 `127.0.0.1`。BlueMap 的 `BLUEMAP_HOST` 必须替换为实际地址；只有 host 模式且 BlueMap 在同一主机时才能用宿主机回环地址。暂时不用 BlueMap 时删除该 location，避免无效域名导致配置检查失败。

保留面板生成的 TLS、域名、root 配置，移除重复的 location 和旧伪静态规则。配置检查通过后重载 OpenResty。管理站保留 API、上传、BlueMap 和 SPA 配置，乘客站额外保留 `/ws`、`/taxilink`。WebSocket 入口通过同一 HTTPS 证书提供 WSS。

## 4. 浏览器安装与模组接入

先按 [Portal 配置](DEPLOYMENT.md#hydcraft-portal-配置)登记两个 HTTPS 回调。生成安装码：

```bash
docker compose --env-file deploy/.env -f deploy/compose.yml exec backend php bin/console.php install-code
```

打开 `https://taxi-admin.example.com/install`，填写安装码、站点地址、数据库、Redis、Portal，使用预定管理员账号完成授权。安装成功后重启后端，使网关和调度进程启动：

```bash
docker compose --env-file deploy/.env -f deploy/compose.yml restart backend
curl --fail https://taxi.example.com/api/health
docker compose --env-file deploy/.env -f deploy/compose.yml logs --tail=100 backend
```

首次安装前只有 HTTP 安装服务，不应以 WebSocket 端口未监听判定安装失败。健康接口检查 HTTP 与数据库，不代表 Portal、Redis、模组与支付全部可用。Workerman 日志也会写入 runtime 卷的 logs 目录，可通过 `exec backend tail -n 100 runtime/logs/workerman.log` 查看。

使用 `docker compose ... cp backend:/app/installation ./installation-export` 安全导出安装目录（`...` 代表上述完整参数），限制导出目录权限。从 `sm2-*/` 中取 `taxilink-mod-private.pem` 与 `taxilink-gateway-public.pem` 放入游戏服 config，按 [SM2 接入说明](DEPLOYMENT.md#sm2-与模组接入)配置 `wss://taxi.example.com/taxilink`。导出目录含私钥，不得放到站点根目录或提交 Git。

## 5. 更新、备份与回滚

2026-11-20 待发布修复新增多乘客端接入及加密 COS 设置，请同时参阅 [保留数据升级方案](UPGRADE_PENDING_20261120.md)，备份 installation 中的 settings.key。v1 与 v2 应部署到各自域名和静态目录，可同时使用。

更新前暂停业务接单，确认活动订单状态，备份数据库、installation、runtime 和游戏服存档及 taxilink-state。可停止后端后用 `docker compose ... cp` 导出两个目录；MySQL 使用逻辑备份，不能直接复制正在运行的数据库文件。备份应记录代码版本、镜像 ID 与数据库版本。

保留旧镜像供回滚，然后构建新镜像、停止旧进程、迁移、启动：

```bash
docker image tag taxilink-backend:local taxilink-backend:previous
docker compose --env-file deploy/.env -f deploy/compose.yml build --pull
docker compose --env-file deploy/.env -f deploy/compose.yml stop backend
docker compose --env-file deploy/.env -f deploy/compose.yml run --rm --no-deps backend php bin/console.php migrate
docker compose --env-file deploy/.env -f deploy/compose.yml up -d --no-deps backend
```

迁移失败时先修复，不继续启动。重新构建、发布前端并验收健康接口、两站 Portal 登录、行程推送、模组重连、图片上传及 BlueMap。镜像构建需要能访问 Docker Hub、Debian 软件源与 Composer 仓库。

回滚时停止后端，将 previous 镜像重新标记为 local，再 `up -d --no-deps backend`（不加 `--build`）。数据库结构不兼容时须配套恢复备份；数据库、配置、游戏状态必须对应同一恢复点。Compose 的默认项目名为 taxilink，不要随意改变，否则会使用另一组卷。普通 `down` 保留命名卷，**不要使用 `down -v`**，它会删除安装密钥及数据。后端保持单副本，不使用 scale 扩容。
