# 真实环境接入

## 从零部署顺序（Linux + Nginx）

以下以项目目录 `/srv/taxilink`、乘客站 `https://taxi.example.com`、管理站 `https://taxi-admin.example.com` 为例。将示例域名全部替换为自己的实际域名；两个域名均解析到部署服务器，并配置有效 HTTPS 证书。当前后端只部署一个实例。

1. 安装 PHP 8.2 或以上的 CLI、Composer、Node.js 22.12 或以上、MySQL 8、Redis、Nginx、OpenSSL 3。PHP 需要 curl、mbstring、PDO 和 pdo_mysql；Linux Workerman 还需要 pcntl、posix，并确保 CLI 未禁用相关函数。后端是常驻 CLI 服务，不通过 PHP-FPM 启动。
2. 获取平台仓库 main，安装依赖并构建前端：

   ```bash
   git clone --branch main https://github.com/LarkerO/HTISingleWindow_TAXILink.git /srv/taxilink
   cd /srv/taxilink
   composer install --working-dir=backend --no-dev --optimize-autoloader
   npm ci
   npm run build
   cp backend/.env.example backend/.env
   mkdir -p backend/runtime backend/keys
   ```

3. 在 MySQL 中创建专用数据库及账号，以下密码必须替换：

   ```sql
   CREATE DATABASE taxilink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'taxilink'@'127.0.0.1' IDENTIFIED BY '替换为独立强密码';
   GRANT ALL PRIVILEGES ON taxilink.* TO 'taxilink'@'127.0.0.1';
   ```

   Redis 使用本机服务；如果启用了认证，按实际凭据配置 `REDIS_URL`。不要将数据库或 Redis 端口公开到互联网。
4. 按下文在 Portal 创建 OAuth 客户端，填写 **`backend/.env`**，并准备 SM2 密钥。正式环境必须使用 `DEMO_MODE=0`。首次管理员必须在第一次登录 TAXILink 之前指定。
5. 初始化数据库：在项目根目录执行 `php backend/bin/console.php migrate`。正式环境不要执行 `seed`，也不要沿用开发演示数据库。
6. 先在项目根目录执行 `php backend/start.php start` 检查启动输出，再交给 systemd 管理。确保服务账号可读取代码、`.env`、密钥，并可写入 `backend/runtime`。私钥和 `.env` 只允许必要的服务账号读取。
7. 将 `deploy/nginx.conf.example` 中域名和 `/srv/taxilink` 路径替换为实际值，接入 HTTPS，检查配置后重载 Nginx。该示例只包含 HTTP 监听，需要自行补充 TLS 站点或放到 HTTPS 终止代理后。站点根目录必须分别指向 `passenger/dist`、`admin/dist`，不能指向仓库或 backend 目录。
8. 配置游戏服模组和 SM2 双向认证，再登录管理端创建企业、邀请管理员、设置车辆中心、车型、虚拟点和运营范围。最后执行本文末尾的上线联调。

systemd 示例（保存为 `/etc/systemd/system/taxilink.service`，服务账号示例为 `www-data`，需与实际文件权限一致）：

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

执行 `sudo systemctl daemon-reload`、`sudo systemctl enable --now taxilink`。通过 `sudo journalctl -u taxilink -n 100 --no-pager` 查看日志。修改 `.env` 或更新后端后执行 `sudo systemctl restart taxilink`。使用 systemd 时不要给启动命令加 `-d`。

Windows 本地联调使用 `cd backend` 后执行 `php windows.php`；上述 systemd 步骤仅适用于 Linux。

## HydCraft Portal 后台具体填写

使用有权限的 Portal 管理账号进入 **`/admin/oauth-clients`**，创建 OAuth 客户端。这里配置的是 Portal 对外提供的 OAuth 客户端，不是 Portal 自己用于登录其他平台的第三方登录设置。

| 字段 | 填写值 |
| --- | --- |
| 名称 | `TAXILink`，也可用自己的应用名称 |
| 回调地址 / Redirect URIs | 下方两个完整地址，每行一个 |
| 权限 / Scopes | 勾选 `profile` 和 `hydroline` |
| 启用状态 | 保持启用 |

```text
https://taxi.example.com/api/auth/callback
https://taxi-admin.example.com/api/auth/callback
```

两端共用一个客户端即可。不要用逗号分隔回调地址，不要填首页、通配符或额外的末尾斜杠。协议、域名、端口、路径必须与后端对应配置精确一致。无需 `email` 或 `directory.read`；`profile` 提供用户名、名称、头像，`hydroline` 提供扩展账号资料。OAuth 权限不等于 TAXILink 的管理权限。

创建成功后保存界面返回的 Client Secret，从客户端列表取得 Client ID，分别填写 `PORTAL_CLIENT_SECRET` 和 `PORTAL_CLIENT_ID`。密钥只放后端 `.env`，不要写进任何 `VITE_*` 变量、前端代码或 Git。`PORTAL_ISSUER` 填实际 Portal 的外部访问根地址（如 `https://hydcraft.cn`），不要追加 `/oauth/authorize` 或 `/api/oauth/token`。

代码已固定使用授权码流程、PKCE S256、`client_secret_basic`，无需在前端手动配置这些协议参数。Portal 创建页面当前只要求名称、回调地址和权限。

### 首个平台管理员

`PLATFORM_ADMIN_SUB` 填你的 **Portal 用户 ID（userinfo 的 `sub`，对应 Portal 数据库 User.id）**，不是用户名、邮箱、Hydroline ID、Minecraft UUID，也不是 OAuth Client ID。可让 Portal 管理员从该账号的用户记录确认 ID，或从已授权的 `/api/oauth/userinfo` 响应读取 `sub`；不要通过猜用户名代替。

先填此值并重启后端，再用对应 Portal 账号首次登录 TAXILink 管理端。系统只在首次创建该本地用户时授予平台管理员身份。没有默认管理员密码，Portal 的 OWNER/ADMIN 身份也不会自动转换为 TAXILink 管理员。

如果该账号已在乘客端或管理端登录过 TAXILink，补填此项不会追溯提升权限，需要对已有账号做受控权限修正。不要通过删除已有用户记录解决，以免影响订单和企业关联。

### 本地 OAuth 联调

按仓库默认 `.env.example`，Portal 另建开发客户端，回调地址每行一个：

```text
http://localhost:8787/api/auth/callback
http://localhost:5174/api/auth/callback
```

对应乘客站 `http://localhost:5173`、管理站 `http://localhost:5174`。真实 OAuth 联调需要关闭演示模式并补齐后端所需配置；`DEMO_MODE=1` 的演示登录不能证明 Portal 已接通。Portal 仅允许 HTTPS 或本机回环 HTTP 回调，不要用公网 HTTP 地址。

### 登录验收与常见问题

- 分别打开两端，主动点击登录提示中的按钮，确认跳转到 Portal、授权后回到发起登录的站点。检查用户名、头像以及管理端权限；同一账号在两端的首次登录日期应一致。
- 回调地址不被接受：检查 Portal 登记值和两个 `PORTAL_*REDIRECT_URI` 的每个字符，包括端口及末尾斜杠。
- `invalid_scope`：客户端需同时允许 `profile`、`hydroline`。
- 换码失败：检查 Client ID/Secret 是否来自同一个启用的客户端，以及后端到 Portal 的网络、HTTPS 证书和服务器时间。
- 登录后仍未登录：检查两个域名的 `/api/` 都代理到后端、实际使用 HTTPS、管理端回调没有误填成乘客域名。Secure Cookie 不会通过普通 HTTP 发送。
- 能登录但没有管理权限：检查 `PLATFORM_ADMIN_SUB` 是否为正确的 Portal sub，且是否在首次创建本地用户之前配置。

以上依据仓库内核对的 Portal v2 源码，真实部署仍需用自己的客户端凭据完成一次线上授权验收。

## 配置

复制 `backend/.env.example` 至 `backend/.env`，正式环境设置：

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

数据库使用 utf8mb4。运行 `composer install --no-dev --optimize-autoloader`、`php bin/console.php migrate`，不要在正式环境 seed。首次登录时按 `PLATFORM_ADMIN_SUB` 引导管理员；更换该设置不自动提升已有用户，已有权限应通过受控数据库运维处理。

开发机的 `.reference/hydcraft-portal` 仅用于核对源码，不打包、不提交。核对版本 `497db20`，依据 `docs/SSO_GUIDE.md`、`server/routes/oauth/authorize.get.ts`、`server/utils/oauth-provider/*` 和 OAuth API 实现。Portal 后台创建 OAuth client，允许 `profile hydroline` scope 及精确回调地址。授权码 + PKCE S256，令牌接口支持 client_secret_basic，本项目使用 JSON 请求；userinfo 的 sub 是唯一账号键。Portal 需与上述接口版本相符。

## SM2 与模组

Portal 客户端必须同时登记乘客端与管理端两个回调地址，分别回到发起登录的站点设置会话 Cookie；不要将管理端回调配置到乘客域名。邀请页与行程页登录后保留本地返回路径。

账号复核基于 Portal `v2` 分支 `497db20` 的授权、token 和 userinfo 实现。运行 `npm run test:oauth` 可验证真实 TAXILink 回调控制器与本地 Portal 协议夹具：JSON 换码、Basic 认证、PKCE S256、两端回调、多条 Cookie、state 重放/过期、用户资料更新及并发首次登录。夹具不是线上 Portal，仍需在部署环境填写 Client ID/Secret 并完成实际授权。

用户身份仅以 Portal `sub` 关联；不要求 Minecraft 绑定，不从 Portal `role` 自动授予平台/企业权限。两端可使用同一 Portal 账号，分别建立应用会话。Portal access/refresh token 不下发浏览器、不保存为长期凭据。本地会话最长两小时；退出只注销当前 TAXILink 会话，不是 Portal 单点登出，Portal 撤销授权或停用账号也不会即时撤销此前的本地会话，下一次 OAuth 登录会重新在线校验。并发登录不会重复创建用户或重置首次登录日期。

升级本次修复前先运行 `php backend/bin/console.php migrate`，新增 OAuth 返回路径与订单版本号，并扩大 MySQL 报价/轨迹 JSON 字段容量。重启后端进程，再发布前端。已由模组回收的 `RECOVERED` 订单可由管理员取消并按失败订单零费用关闭，保留审计与演示退款记录。

复用 TAXILink_MOD 的 `examples/php/init-sm2.ps1` 生成两套独立密钥，或依据其协议用 OpenSSL 3 生成 SM2 密钥。业务后端保存网关私钥和固定的模组公钥；游戏服保存模组私钥和固定的网关公钥，私钥不得进入浏览器或 Git。

模组 endpoint 指向 `wss://taxi.example.com/taxilink`，serverId、dimension 与业务后端一致。使用包含 `pickupDistanceMeters`、`returnDistanceMeters` 以及车辆丢失/生成期乘客保护修复的模组版本；满员、超时及主动确认发车规则保持不变。

在模组现有调试工具中授权单轨专用车厂；业务后台读取 MTR 目录并建立车辆中心、真实车型及虚拟点映射。业务候补采用具体车厂派车，不向模组的多个独立中心同时下单。

## 服务启动及代理

Linux 使用 systemd/supervisor 管理 `php backend/start.php start` 前台进程。所有 worker 默认绑定回环地址。调度文件锁依赖同一 runtime 目录，当前只支持单实例部署。

构建前端 `npm ci && npm run build`。参照 `deploy/nginx.conf.example` 配置两个站点并为正式域名配置 HTTPS；同源代理 `/api` 与 `/ws`，模组使用乘客域名 `/taxilink`。外层 TLS 终止后再转给示例 HTTP 站点亦可。不要对外暴露 8789 内部 RPC，关闭未知代理头透传。代理 WebSocket Upgrade、Connection 与 `X-TAXILink-Proof`。

每日备份 MySQL，保留模组存档中的 TAXILink 状态日志；恢复两端时核对活动订单，禁止清理去重日志后自动重发。Redis 仅存限流数据，丢失不会丢订单。

## 上线前联调

本地已测试模拟模组、SQLite 领域逻辑、HTTP API、浏览器登录提示及打车流程。以下须在目标环境验证：Portal 注册与回调、MySQL/Redis 凭据、SM2 双向签名、真实车厂与轨道、满员/超时自动放行、断线重连、模组重启恢复、有人时取消、实际轨迹计费及演示补退款。

收银台仍显示“演示支付”。未来真实游戏货币需新增经济服务适配与支付对账，当前没有任何真实扣款。

可选跨仓库契约测试的启动方法见模组仓库 `docs/ACCEPTANCE.md`。平台测试入口 `backend/tests/mod-gateway-server.php` 只允许测试环境启动，使用独立数据库与随机回环端口。内部 RPC 默认端口为 8789；若通过 `GATEWAY_RPC_PORT` 修改，须同步修改 `GATEWAY_URL`，仍只监听 127.0.0.1。
