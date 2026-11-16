# TAXILink 部署指南

[本机测试安装](#本机测试安装) · [生产安装](#生产安装) · [Portal 配置](#hydcraft-portal-配置) · [目录权限](#服务账号与目录权限) · [模组接入](#sm2-与模组接入) · [更新与验收](#更新备份与验收)

| | 本机测试安装 | 生产安装 |
| --- | --- | --- |
| 浏览器入口 | 本机 HTTP，可选端口 | 独立 HTTPS 域名 |
| 数据库 | 向导创建 SQLite | 预建专用空 MySQL 8 库 |
| 限流 | SQLite，共享计数 | Redis |
| 业务 | Portal、企业权限、调度、WebSocket、SM2、演示支付 | 同左 |

浏览器向导供尚无 `backend/.env`、外部 `DB_DSN` 和安装配置的实例使用。已有数据的站点走升级流程。只想体验界面可使用 README 的[演示模式](../README.md#快速体验)。

## 先准备依赖

需要 PHP 8.2+ CLI、curl、mbstring、对应的 PDO 驱动、Composer、Node.js 22.12+ 和 OpenSSL 3 命令行。Linux 还需要 pcntl 和 posix。以下命令均在项目根目录运行：

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

3. 在 Portal 创建测试 OAuth 客户端，勾选 `profile` 和 `hydroline`，分别登记两个回调：

   ```text
   http://localhost:15174/api/auth/callback
   http://127.0.0.1:15173/api/auth/callback
   ```

4. 打开 `http://localhost:15174/install`。输入安装码、乘客端地址和 Portal 凭据，使用预定管理员账号完成授权。向导会创建 `backend/installation/taxilink.sqlite`，校验 SM2 并保存配置。
5. 页面显示安装成功后，停止并重新启动后端，再点“检查服务状态”。本次授权建立的管理员会话可继续使用。启用同一台机器上的真实模组时，参见 [SM2 与模组接入](#sm2-与模组接入)。

## 生产安装

以下按 Linux + Nginx 部署。

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
- `invalid_scope` 时检查是否同时勾选两项权限。
- 换码失败时检查客户端凭据、Portal 地址、服务器时间和 HTTPS 证书。Windows 上未设置 `curl.cainfo` 时，支持原生 CA 的 PHP cURL 会使用 Windows 信任库。若仍提示证书错误，用 `php --ini` 确认 CLI 配置及所用 CA，修改后重启后端。
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

在模组工具中授权单轨专用车厂。管理后台读取 MTR 目录后创建车辆中心、车型和虚拟点映射。

## 更新、备份与验收

- 浏览器安装配置和私钥位于 `backend/installation`。升级时保留该目录与手动配置文件，备份数据库及模组状态日志。更新后运行 `php backend/bin/console.php migrate`，重启后端并发布两端静态产物。
- 本地检查运行 `npm test`、`npm run build`。OAuth 协议夹具覆盖 PKCE、回调和并发登录。正式上线仍须用实际 Portal 客户端验收授权。
- 游戏服联调检查真实车厂与轨道、手动及自动发车、断线重连、模组重启恢复、轨迹计费和演示补退款。恢复备份时先核对活动订单和模组去重日志。
- 后端采用单实例调度。改动内部 RPC 端口时同步修改 `GATEWAY_RPC_PORT` 和 `GATEWAY_URL`。跨仓库联调细节见模组仓库的 `docs/ACCEPTANCE.md`。
