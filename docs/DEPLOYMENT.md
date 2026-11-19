# TAXILink 部署指南

使用 1Panel / OpenResty 时，参见 [Docker Compose 部署说明](DEPLOYMENT_1PANEL.md)，支持复用面板数据库或独立 MySQL、Redis。

[本机测试安装](#本机测试安装) · [生产安装](#生产安装) · [Portal 配置](#hydcraft-portal-配置) · [目录权限](#服务账号与目录权限) · [模组接入](#sm2-与模组接入) · [更新与验收](#更新备份与验收)

| | 本机测试安装 | 生产安装 |
| --- | --- | --- |
| 浏览器入口 | 本机 HTTP，可选端口 | 独立 HTTPS 域名 |
| 数据库 | 向导创建 SQLite | 预建专用空 MySQL 8 或 PostgreSQL 14+ 库 |
| 限流 | SQLite，共享计数 | Redis |
| 业务 | Portal、企业权限、调度、WebSocket、SM2、支付 | 同左 |

浏览器向导供尚无 `backend/.env`、外部 `DB_DSN` 和安装配置的实例使用。已有数据的站点走升级流程。只想体验界面可使用 README 的[演示模式](../README.md#快速体验)。

## 先准备依赖

需要 PHP 8.2+ CLI、curl、mbstring、对应的 PDO 驱动（MySQL 为 pdo_mysql，PostgreSQL 为 pdo_pgsql）、Composer、Node.js 22.12+ 和 OpenSSL 3 命令行。Linux 还需要 pcntl 和 posix。以下命令均在项目根目录运行：

```text
composer install --working-dir=backend --no-dev --optimize-autoloader
npm ci
```

OpenSSL 加入 PATH，或通过进程环境变量 `TAXILINK_OPENSSL` 指定可执行文件绝对路径。安装前运行 `php -m` 和 `openssl version` 检查环境。

Windows 安装向导会自动识别默认路径下 Git for Windows 附带的 OpenSSL。也可从 [Git for Windows 官方发布页](https://github.com/git-for-windows/git/releases/latest) 下载 PortableGit，解压后将 `TAXILINK_OPENSSL` 指向包内的 `usr\bin\openssl.exe`，并保留同目录的依赖 DLL。

## 本机测试安装

Windows 和 Linux 均可使用此流程。安装和运行使用同一个普通账号。测试后端和前端仅监听回环地址。管理站与乘客站使用不同主机名隔离 Cookie，例如管理站 `http://localhost:15174`、乘客站 `http://127.0.0.1:15173`。端口可以更换，Portal 回调也要同步更新。

1. 在服务器终端生成安装码并启动后端。安装码有效期 30 分钟，新生成的码会替换旧码。

   Windows PowerShell：

   ```powershell
   $env:TAXILINK_LOCAL_INSTALL = '1'
   # OpenSSL 不在 PATH 时，填安装路径
   # $env:TAXILINK_OPENSSL = 'C:\Program Files\Git\usr\bin\openssl.exe'
   php .\backend\bin\console.php install-code
   php .\backend\windows.php
   ```

   Linux：

   ```bash
   php backend/bin/console.php install-code
   TAXILINK_LOCAL_INSTALL=1 php backend/start.php start
   ```

2. 在另外两个终端启动 Vite。两个前端的 API 代理默认指向 `127.0.0.1:8787`。

   ```text
   npm run dev -w admin -- --host 127.0.0.1 --port 15174 --strictPort
   npm run dev -w passenger -- --host 127.0.0.1 --port 15173 --strictPort
   ```

   如果开发页面提示 `Failed to fetch dynamically imported module`，且地址包含 `/node_modules/.vite/deps/`，先停止旧的管理端 Vite 进程，在管理端启动命令末尾加 `--force`，然后在浏览器用 Ctrl+F5 重新加载。开发服务使用自己的依赖缓存，`npm run build` 生成的 `admin/dist` 供静态部署使用。

3. 在 Portal 创建测试 OAuth 客户端，勾选 `profile` 和 `hydroline`，分别登记两个回调：

   ```text
   http://localhost:15174/api/auth/callback
   http://127.0.0.1:15173/api/auth/callback
   ```

4. 打开 `http://localhost:15174/install`。输入安装码、乘客端地址和 Portal 凭据，使用预定管理员账号完成授权。向导会创建 `backend/installation/taxilink.sqlite`，校验 SM2 并保存配置。
5. 页面显示安装成功后，停止并重新启动后端，再点“检查服务状态”。本次授权建立的管理员会话可继续使用。启用同一台机器上的真实模组时，参见 [SM2 与模组接入](#sm2-与模组接入)。

## 生产安装

以下按 Linux + Nginx 部署。

默认步骤使用 MySQL；选择 PostgreSQL 时，第 2 步的建库命令替换为下方[使用 PostgreSQL](#使用-postgresql)的说明。

1. 将项目部署到 `/srv/taxilink`，为管理端和乘客端分别准备 HTTPS 域名。安装依赖后，在项目根目录运行 `npm run build`，产物位于 `admin/dist` 和 `passenger/dist`。
2. 在 MySQL 中创建专用空库和账号，替换示例密码：

   ```sql
   CREATE DATABASE taxilink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'taxilink'@'127.0.0.1' IDENTIFIED BY '替换为独立强密码';
   GRANT ALL PRIVILEGES ON taxilink.* TO 'taxilink'@'127.0.0.1';
   ```

   准备 Redis。MySQL 和 Redis 如与后端同机，应仅监听回环地址。
3. 按[目录权限](#服务账号与目录权限)准备 `backend/runtime` 和 `backend/installation`。以服务账号运行 `php backend/start.php start`，由 systemd 管理前台进程。
4. 参考 `deploy/nginx.conf.example` 配置两个站点及 TLS。该示例使用 HTTP 监听，可由前置代理终止 TLS。站点根目录分别指向两个 `dist`，保留 SPA 回退和 `/api/` 转发。乘客站还需转发 `/ws` 和 `/taxilink`，保留 WebSocket 升级头与 `X-TAXILink-Proof`。内部 RPC 8789 只供本机调用。
5. 按 [Portal 配置](#hydcraft-portal-配置)创建 OAuth 客户端。以 systemd 的服务账号生成安装码：

   ```bash
   sudo -u www-data php /srv/taxilink/backend/bin/console.php install-code
   ```

6. 从管理端 HTTPS 地址访问 `/install`，填写安装码、站点地址、数据库、Redis 和 Portal 信息，用预定管理员账号完成授权。看到成功提示后执行 `sudo systemctl restart taxilink`，检查服务状态并进入管理端。

### systemd 示例

保存为 `/etc/systemd/system/taxilink.service`，按实际账号和路径调整：

```ini
[Unit]
Description=TAXILink backend
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/srv/taxilink/backend
ExecStart=/usr/bin/php /srv/taxilink/backend/start.php start
Restart=on-failure
RestartSec=5
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

执行 `sudo systemctl daemon-reload` 和 `sudo systemctl enable --now taxilink`。通过 `sudo journalctl -u taxilink -n 100 --no-pager` 查看日志。

### 使用 PostgreSQL

生产安装也可选择 PostgreSQL 14+。无需 Docker，PHP 后端仍由 systemd 启动，前端静态目录与 OpenResty 配置不变。在 1Panel 创建 UTF8 编码的专用空库和账号，确保账号可以连接数据库并在 public schema 中建表。也可由数据库管理员执行（替换密码）：

```sql
CREATE ROLE taxilink LOGIN PASSWORD '替换为独立强密码';
CREATE DATABASE taxilink OWNER taxilink ENCODING 'UTF8' TEMPLATE template0;
```

使用已有数据库时，连接到目标库后授权：

```sql
GRANT CONNECT ON DATABASE taxilink TO taxilink;
GRANT USAGE, CREATE ON SCHEMA public TO taxilink;
```

在管理端 `/install` 选择 PostgreSQL，默认端口为 5432，填写实际主机、端口、数据库名、账号和密码，后续 Portal、Redis、SM2 流程相同。安装要求只需具备所选数据库的 PDO 扩展；安装扩展后重启 PHP CLI 后端。后端与数据库同机时优先使用实际可达的本机地址及映射端口；远程连接还需配置 PostgreSQL 的监听地址、pg_hba.conf 和网络放行。

手动配置时设置：

```dotenv
DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=taxilink;sslmode=prefer;connect_timeout=3
DB_USER=taxilink
DB_PASSWORD=自行设置
```

其余生产配置保持一致，运行 `php backend/bin/console.php migrate` 后启动。安装向导默认使用 public schema（账号的默认 current_schema 应为 public），不支持在一个 schema 混放其他应用表。向导连接使用 sslmode=prefer；需要强制验证远程服务器证书时，通过手动配置的 DSN 设置 sslmode=verify-full 和 sslrootcert。浏览器安装实例的业务配置保存在 installation/config.json，不应同时用另一份 .env 覆盖它。

此适配支持空库初始化及同数据库类型的结构升级，不自动搬运 MySQL 已有数据。已有生产数据改用 PostgreSQL 前，需另做数据迁移与核对，不能只更改 DSN。Redis 仍是生产必需依赖。

### 数据库对照验证

`npm run test:database` 使用真实 MySQL 或 PostgreSQL，依次创建随机命名的 taxilink_test_* 临时数据库，运行安装/升级/锁/并发登录与下单契约、领域回归、收银台与 OAuth 协议夹具，再删除临时库。传入的管理库不运行夹具、不删表；测试账号需要创建和删除数据库的权限。仅在专用测试实例运行：

```bash
TAXILINK_TEST_ADMIN_DSN='pgsql:host=127.0.0.1;port=5432;dbname=postgres' \
  DB_USER=测试管理员 DB_PASSWORD=测试密码 npm run test:database
# 对照 MySQL 时将管理 DSN 改为 mysql:host=127.0.0.1;port=3306;dbname=mysql
```

默认 `npm test` 继续使用 SQLite，不依赖外部数据库。收银台测试使用本地支付协议夹具，不向真实支付平台发起扣款。

2026-11-19 本地对照：Windows / PHP 8.5.12，PostgreSQL 17.11 与 MySQL 8.4.13 均通过上述数据库契约、80 项领域验证、16 项收银台验证及 7 项 OAuth 测试；SQLite 领域与首次初始化回归、两端类型检查及生产构建通过。此记录不代替生产 Linux 环境、真实 Portal 和游戏服的上线联调。

## 服务账号与目录权限

以下用 `www-data` 作为后端服务账号。若 systemd 的 `User=` 或 `Group=` 不同，请替换为实际值。管理员以部署账号构建前端和安装 Composer 依赖，后端服务账号只需读取代码与依赖。Nginx 工作进程需要读取 `admin/dist` 和 `passenger/dist`。

推荐部署在 `/srv/taxilink`。在项目根目录执行以下命令，创建后端专用的可写目录，再以服务账号验证权限：

```bash
project="$(pwd -P)"
sudo install -d -m 0700 -o www-data -g www-data "$project/backend/runtime" "$project/backend/installation"
sudo -u www-data test -x "$project/backend" &&
  sudo -u www-data test -r "$project/backend/bin/console.php" &&
  sudo -u www-data test -r "$project/backend/vendor/autoload.php" &&
  sudo -u www-data test -w "$project/backend/runtime" &&
  sudo -u www-data test -w "$project/backend/installation" &&
  echo "权限检查通过"
```

如果已经用其他账号启动过服务，检查 `runtime` 内的既有日志、锁和缓存是否也可由服务账号访问。已有 `installation` 目录可能包含正式配置和私钥，调整归属前先确认它对应当前实例。

若仓库在家目录，`chdir(): Permission denied` 通常表示服务账号缺少某一级目录的进入权限。用以下命令查看路径上的每一级：

```bash
namei -l "$project/backend/vendor/autoload.php"
```

可以迁移到 `/srv/taxilink` 并同步修改 systemd 和 Nginx 路径。若保留在家目录，可用 POSIX ACL 按需授权。比如仅家目录缺少权限时，从部署账号的终端执行：

```bash
sudo setfacl -m u:www-data:--x "$HOME"
```

如果 `namei` 显示项目目录或其他上级目录也有限制，对对应目录同样授予服务账号 `--x`。如果后端代码、Composer 依赖或前端产物无法读取，仅对所需的目录和文件授权，目录需要 `x`，文件需要 `r`。家目录只授予进入权限即可。避免 `chmod -R 777`，并保留配置和私钥的访问限制。权限检查通过后，运行 `sudo -u www-data php "$project/backend/bin/console.php" install-code`。

## HydCraft Portal 配置

在 Portal 的 `/admin/oauth-clients` 创建并启用客户端。名称可用 `TAXILink`，权限选 `profile` 和 `hydroline`。一个客户端可供两端共用，回调每行填写一个完整地址。例如：

```text
https://taxi-admin.example.com/api/auth/callback
https://taxi.example.com/api/auth/callback
```

本机测试使用[上面的回调示例](#本机测试安装)。协议、主机名、端口和路径须逐字匹配实际地址。Portal 服务本身使用实际 HTTPS 根地址。保存创建时取得的 Client ID 和 Client Secret，Secret 只保存在后端。

浏览器安装将完成授权的账号设为首位平台管理员。普通用户以后按 Portal `sub` 关联，平台与企业权限在 TAXILink 内管理。两端各有自己的两小时会话。Portal 停用账号或撤销授权后，下一次 OAuth 登录会在线检查。

- 回调被拒绝时，核对 Portal 登记的 URI 及浏览器地址。
- 已安装后更换乘客端端口时，在 Portal 登记新的乘客端回调，并同步修改 `backend/installation/config.json` 中的 `APP_ORIGIN` 和 `PORTAL_REDIRECT_URI`。手动配置的实例修改 `backend/.env` 中同名项。重启后端，确认前端在新端口监听，再从新乘客端地址发起登录。授权过程中请使用完整回调，授权码和 state 绑定原回调，手改浏览器地址无法迁移这次授权。Vite 开发服务建议用 `--strictPort`，端口被占用时会直接报错。
- 报价时提示站点地址不一致，请使用页面提示的乘客端地址。浏览器地址的协议、主机名和端口都要与 `APP_ORIGIN` 一致。本机的 `localhost` 和 `127.0.0.1` 是两个不同的来源。
- `invalid_scope` 时检查是否同时勾选两项权限。
- 安装页会显示授权回调、换码或读取资料的失败阶段，以及可用的 HTTP 状态、Portal 错误码或 cURL 编号。换码失败时按错误码检查客户端凭据、回调地址、服务器时间或 HTTPS 证书。Windows 上未设置 `curl.cainfo` 时，支持原生 CA 的 PHP cURL 会使用 Windows 信任库。若仍提示证书错误，用 `php --ini` 确认 CLI 配置及所用 CA，修改后重启后端。
- 回来仍未登录时检查同源 `/api/` 代理，以及是否使用登记时的主机名和端口。
- 迁移中断时修复存储权限，使用原数据库和原授权账号重试。安装码过期可重新签发。

## 手动配置

已有 `backend/.env` 的实例继续通过文件配置。全新生产部署如需手动安装，可从 `backend/.env.example` 复制后填写：

```dotenv
APP_ENV=production
DEMO_MODE=0
APP_SECURE_COOKIE=1
APP_ORIGIN=https://taxi.example.com
ADMIN_ORIGIN=https://taxi-admin.example.com
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=taxilink;charset=utf8mb4
DB_USER=taxilink
DB_PASSWORD=自行设置
REDIS_URL=tcp://127.0.0.1:6379
PORTAL_ISSUER=https://hydcraft.cn
PORTAL_CLIENT_ID=在Portal后台创建
PORTAL_CLIENT_SECRET=仅保存于后端
PORTAL_REDIRECT_URI=https://taxi.example.com/api/auth/callback
PORTAL_ADMIN_REDIRECT_URI=https://taxi-admin.example.com/api/auth/callback
PLATFORM_ADMIN_SUB=首个平台管理员的Portal用户sub
GATEWAY_TOKEN=至少32字符的独立随机令牌
TAXILINK_SERVER_ID=taxilink-01
TAXILINK_DIMENSION=minecraft/overworld
TAXILINK_OPENSSL=/usr/bin/openssl
TAXILINK_GATEWAY_PRIVATE_KEY=keys/gateway-private.pem
TAXILINK_MOD_PUBLIC_KEY=keys/mod-public.pem
```

运行 `php backend/bin/console.php migrate` 后启动后端。首位管理员按 `PLATFORM_ADMIN_SUB` 在第一次创建本地账号时授权，填写的是 Portal userinfo 的 `sub`。正式实例禁止执行 `seed`。

## SM2 与模组接入

浏览器向导会自动生成两套密钥并校验双向签名。安全复制 `backend/installation/sm2-*/` 下的 **`taxilink-mod-private.pem`** 和 **`taxilink-gateway-public.pem`** 到游戏服的 `config/`。手动部署可用模组仓库的 `examples/php/init-sm2.ps1` 生成对应文件。私钥保存在服务账号可读的目录。

在模组的 `config/taxilink.json` 启用连接，填写密钥路径和与安装向导一致的服务器 ID。游戏维度须与后端一致。生产 endpoint 示例为 `wss://taxi.example.com/taxilink`。同机测试可用 `ws://127.0.0.1:8788/taxilink`，并启用模组的 `allowInsecureLocalhost`。

模组连接后，平台管理员可在「车厂授权」授权或撤销单轨专用车厂，修改即时写入当前存档的模组授权名单，并记录在「操作日志」。只有已授权且拥有一条所属停车轨道的车厂可加入车辆中心。撤销仍被车辆中心引用的车厂后，请调整对应中心。管理后台读取 MTR 目录后创建车辆中心、车型和虚拟点映射。

首次安装后，平台管理员从「运营总览」的「创建运营企业」开始。创建后会自动选中首家企业。先配置车辆中心和车型，再建立虚拟点映射，最后为企业设置运营范围和计费规则。模组连接成功后即可读取游戏目录，运营企业由平台管理员创建。

在「车型管理」新增或编辑车型时，使用「读取游戏轨道」选取 MTR 中已有车辆编组的车厂停车轨道，再点击「载入并替换当前编组」。可以在表单中修改车辆资源、每节载客人数、车身参数与行驶参数后保存。平台保存的是派车用车型，原轨道配置仍在 MTR 内维护。最高速度由车型表单设置。

管理端概览会分别显示网关不可用、网关在线但模组未连接、模组已连接。网关不可用时先检查 PHP 后端日志和内部 RPC 端口 8789。同机测试若网关在线但模组未连接，确认游戏实例的 `config/taxilink.json` 已启用，WebSocket 地址指向模组网关的 8788 端口，`serverId` 与后端一致，且模组配置可读到配套的两份 SM2 文件。修改后退出并重新进入存档，查看游戏实例的 `logs/latest.log` 中的 TAXILink 启动或连接错误。

平台管理员可在「系统日志」查看最近 1000 条 WebSocket 事件。用请求 ID 对照「指令下发」和「指令结束」。总耗时从网关发送到收到模组响应、超时或断线，包含传输和模组排队时间。日志保存在数据库中，升级后先运行迁移再重启后端。

## 更新、备份与验收

- 浏览器安装配置和私钥位于 `backend/installation`。升级时保留该目录与手动配置文件，备份数据库及模组状态日志。更新后运行 `php backend/bin/console.php migrate`，重启后端并发布两端静态产物。
- 本地检查运行 `npm test`、`npm run build`。OAuth 协议夹具覆盖 PKCE、回调和并发登录。正式上线仍须用实际 Portal 客户端验收授权。
- 游戏服联调检查真实车厂与轨道、手动及自动发车、断线重连、模组重启恢复、轨迹计费和演示补退款。恢复备份时先核对活动订单和模组去重日志。
- 后端采用单实例调度。改动内部 RPC 端口时同步修改 `GATEWAY_RPC_PORT` 和 `GATEWAY_URL`。跨仓库联调细节见模组仓库的 `docs/ACCEPTANCE.md`。

## BlueMap 底图

1. 在游戏服务器启动 BlueMap 并完成目标地图渲染。本文使用 BlueMap 5.7，默认主世界地图 ID 为 `heishan7_0`。BlueMap 根目录的 `settings.json` 中列出了地图 ID。
2. 开发时，两端 Vite 将 `/bluemap/` 转发到本机 `8100`。生产部署请在两个站点配置 [nginx 示例](../deploy/nginx.conf.example) 的 `/bluemap/` 代理，并将上游地址改为 Web 服务器可访问的 BlueMap 地址。
3. 已部署的后端升级时运行 `php backend/bin/console.php migrate`，创建保存 BlueMap 设置的表。
4. 平台管理员打开管理端的 **BlueMap 设置**，填写地图 ID，选择是否向两端开放 3D 并保存。两端在重新打开地图时读取配置，默认以 2D 打开。

2D 从 `/bluemap/maps/<地图 ID>/settings.json` 读取 `lowres.tileSize`、`lodFactor` 和 `lodCount`，按可见范围请求 `tiles/<LOD>/x<索引>/z<索引>.png`。负坐标保留减号，多位索引按数字拆成路径段。图层按 Minecraft X/Z 坐标叠加。初次部署可在构建时设置 `VITE_BLUEMAP_ROOT` 指定后端设置为空时使用的地图目录。

3D 通过同源代理加载 BlueMap 原生视图，并定位到当前地图区域。订单轨迹、实时车辆位置和表单选点保留在 TAXILink 的 2D 视图里。
