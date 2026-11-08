# 真实环境接入

## 配置

复制 `backend/.env.example` 至 `.env`，正式环境设置：

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
