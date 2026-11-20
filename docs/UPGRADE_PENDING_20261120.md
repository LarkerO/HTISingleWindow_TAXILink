> 历史内测记录；0.5.2 已进入正式发布流程，当前升级要求以 RELEASE_0.5.2.md 为准。文中“不发布/待发布”描述的是当时阶段。

# 内测问题修复与保留数据升级方案（待发布）

本轮仅准备代码与本地验证，不改版本号、不创建 Release、不部署远端。待后续问题合并并经确认后统一发布。游戏模组协议及存档格式不变，本轮无需更换模组。

## 修复范围

1. **报价。** 原来按企业、中心、车厂、车型、上下车轨道组合逐个等待模组，每次最多等待约 20 秒，整次请求没有预算。现改为最多 4 路并行，重复参数合并、成功预览缓存 20 秒，网关查询总预算 18 秒；两个乘客端请求超时为 25 秒。缓存只复用路线，企业计费每次重新计算，订单和派车仍执行原有校验。超过预算时，有成功结果则返回可用报价及 `complete=false`、`warning`；没有成功结果则返回明确的 JSON 错误。空响应、HTML 代理错误页、截断 JSON 不再直接显示英文解析异常。18 秒是网关等待预算，数据库和响应传输仍有额外耗时。
2. **3D 地图。** 2D 保持默认；3D 降低超采样和高精度几何加载范围，检查实际绘制、BlueMap 初始化错误及 WebGL 上下文丢失。约 20 秒未渲染或出现错误时恢复 2D，保留路线和位置。页面离开时释放渲染器。浏览器没有 WebGL2 或设备驱动拒绝创建上下文时仍无法强制显示 3D；本次处理可避免黑屏阻断使用。
3. **多乘客端。** 管理台新增“乘客端接入”，原 `APP_ORIGIN` 始终保留。额外地址写入数据库并即时作用于 CSRF、演示登录来源检查和 WebSocket 握手。Portal 登录把发起端绑定到一次性 state，换码和回跳使用对应端的回调；账号、订单和企业权限共用同一数据库，各域使用独立会话 Cookie。未来乘客端可继续登记。
4. **COS。** 管理台可填写桶、地域、SecretId、SecretKey、HTTPS 公开根地址，即时生效。凭据不回显，留空保留；使用 AES-256-GCM 加密后写入数据库，密钥保存在后端 `installation/settings.key`（或 `SETTINGS_KEY_FILE`）。未保存管理台设置时继续读取原 COS 环境变量，兼容旧部署；保存后数据库设置优先。

截图中的 `Unexpected end of JSON input` 只能证明响应为空或被截断，不能单凭截图确认代理、PHP 超时或后台异常的具体原因。2026-11-20 只读检查时，v2 的公开 API、地图设置、瓦片、3D 页面及入口脚本均能访问；未在真实账号上执行下单、付款或 COS 上传。需在正式发布前用实际线路及手机复验。

## 升级前备份

暂停新接单，等待活动行程、支付与退款处理稳定后停止后端，记录代码版本和旧镜像 ID；保存：

- **完整数据库**：账号、会话、企业、车型、站点、计费、订单、支付、公告、审计等所有表。MySQL / PostgreSQL 用一致性逻辑备份。SQLite 停进程后备份整个 runtime，连同存在的 `-wal`、`-shm` 文件一起保留，不直接热拷贝主文件。
- **backend/installation 整个目录**：含安装配置、SM2 密钥、安装锁，以及本轮保存 COS 后生成的 `settings.key`。手动部署还需保存 `.env` 和外部 keys 目录；使用 `SETTINGS_KEY_FILE` 时另备份实际密钥文件。
- **runtime、两套乘客端及管理端静态目录、OpenResty 配置、游戏存档与 taxilink-state**。
- **COS 原桶和已上传对象**保持可读；更换桶或 CDN 地址不会搬迁旧图片，历史订单仍引用旧 URL。

密钥与数据库备份分开限制访问；恢复 COS 设置必须配套恢复 settings.key。不要重新访问 `/install`，不要清库、重新 seed 或删除 Docker 命名卷。

## 迁移与启动

1Panel 安装沿用已有项目名、数据库和卷。下列命令在业务仓库根目录执行，仅供下一轮确认后的升级使用：

```bash
docker image tag taxilink-backend:local taxilink-backend:previous
docker compose --env-file deploy/.env -f deploy/compose.yml build
docker compose --env-file deploy/.env -f deploy/compose.yml stop backend
docker compose --env-file deploy/.env -f deploy/compose.yml run --rm --no-deps backend php bin/console.php migrate
docker compose --env-file deploy/.env -f deploy/compose.yml up -d --no-deps backend
```

手动部署则停止旧进程、替换业务代码并保留 `.env` / installation / runtime / keys，在 backend 执行 `php bin/console.php migrate`，成功后启动或重启所有 webman 进程。PHP 需启用 OpenSSL 扩展以保存、读取加密 COS 配置。

新增 `system_settings`、`route_cache` 两张表，并在 `oauth_states` 增加可空 `client_origin` 列；旧数据不重建。迁移可重复执行，保留既有主键、账号 sub、订单及支付记录。升级前已发起的旧 OAuth state 继续使用原乘客端配置，新增 state 绑定发起端。迁移失败先处理，不启动新后端。

随后在本地构建并发布管理端、Vue v1 和 v2 H5 的静态文件。两个乘客端使用各自站点目录，不互相覆盖；v2 工程用 HBuilderX 构建。静态页面和 API 要在同一个维护窗口更新，避免旧页面继续发起未携带端标识的授权请求。

## 登记并行乘客端

保留原值：

```dotenv
APP_ORIGIN=https://taxi.htisw.bg7qvu.cn
ADMIN_ORIGIN=https://console.taxi.htisw.bg7qvu.cn
PORTAL_REDIRECT_URI=https://taxi.htisw.bg7qvu.cn/api/auth/callback
PORTAL_ADMIN_REDIRECT_URI=https://console.taxi.htisw.bg7qvu.cn/api/auth/callback
```

在管理台“乘客端接入”加入 `https://passv2.taxi.htisw.bg7qvu.cn`，并在 Portal 同一个 OAuth 应用登记 `https://passv2.taxi.htisw.bg7qvu.cn/api/auth/callback`。地址精确匹配协议、主机和端口，不带末尾斜杠、路径或通配符。也可通过可选 `PASSENGER_ORIGINS` 环境变量添加逗号分隔的源地址；环境变量变更需要重启。管理台删除地址不会移除 APP_ORIGIN / PASSENGER_ORIGINS 中的固定项。

每个站点分别代理 `/api/`、`/ws`、`/bluemap/`；保留实际 Origin 和 Cookie。v2 经管理站代理 API 时可以使用上游 Host/SNI，但不要改写 Origin。各站点使用同源 BlueMap，避免 iframe 无法访问地图对象。代理读取超时至少覆盖 25 秒，地图资源需保留 JS MIME、相对路径及二进制内容。无需开放通配 CORS。

第三方乘客端授权入口采用 `/api/auth/start?clientOrigin=<URL编码后的当前源>&returnTo=<本地页面路径>`；新端默认回调为其源地址加 `/api/auth/callback`，必须在 Portal 登记。未提供 clientOrigin 的旧入口尝试使用已登记 Referer，否则使用原 APP_ORIGIN。原生 App 使用配置的 HTTPS 站点作为 clientOrigin。

## 验收与回滚

- 对比升级前后的用户数、订单数、支付记录数和典型历史订单金额；重复 migrate 后不应变化。
- v1、v2 分别从新浏览器会话完成 Portal 登录，同一账号看到相同行程；各自获取报价、提交订单及接收 `/ws` 推送。未登记来源仍须被拒绝。
- 实际线路复验报价耗时、刷新命中缓存、不同人数和车型、无路可达、模组离线、部分超时；正常报价与下单金额应一致。缓存不会保证模组当前在线或车厂空闲，派车结果以现有派车校验为准。
- Android Edge 等真实设备验证 3D 实际渲染；关闭 WebGL2 或模拟初始化失败后应恢复 2D，行程操作继续可用。
- 管理台填写 COS 后上传真实测试图片；检查公开 URL、历史图片可读，刷新管理台不回显 SecretId / SecretKey。普通用户与企业管理员不能读写全平台 COS 设置。

若需回滚，暂停接单并停止新进程，保留故障现场及新发生的交易记录，再恢复旧镜像和静态文件。本轮表/列为增量，旧代码可忽略；旧代码不读取新的 COS 设置和额外来源，回滚前需确认旧环境变量可用。优先只回退代码，避免丢失升级后业务记录。确需恢复数据库时必须明确处理升级后的订单/付款/退款并使用同一恢复点的配置和游戏状态。禁止在存在新交易时直接覆盖数据库，禁止 `docker compose down -v`。

## 本地验证记录

- 113 项 PHP 领域检查通过，含多端来源、实际 WebSocket 握手处理器、COS 加密与权限，以及重复迁移前后用户/订单/支付记录完整保留。
- 完整 npm test 通过；新增报价回归进一步验证 4 路并发、20 秒缓存、整体截止时间、异常响应隔离、单个企业失败仍显示其他报价。Portal 契约含第二乘客端独立回调和复用同一身份。空 JSON / HTML / 截断响应、3D 健康检测及上下文丢失回归通过。
- 收银台签名、到账确认、幂等退款及不确定响应恢复回归通过。
- 管理端和 Vue v1 类型检查及生产构建通过。v2 H5、Android Vapor bytecode、iOS Vapor bytecode 本地编译通过；原生仍有项目已有的 gap 样式兼容警告，本轮未生成签名安装包。
- 三项无头 Edge 浏览器交互验证通过：管理台保存 COS / 接入表单并清空凭据、Vue v1 3D 初始化失败及 WebGL 丢失后恢复 2D、v2 H5 初始化失败后恢复 2D。验证截图位于本地 artifacts/review-20261120/。
- 本轮没有可用的 MySQL / PostgreSQL 测试实例，实际数据库升级需在备份副本验证；SQLite 增量迁移和记录保留已验证。真实 COS 上传、手机 GPU/WebGL2、真实线路的耗时和承运结果仍需在内测环境复核。

本文不代表已经执行生产升级；初始修复阶段保留本地；按后续明确授权提交并推送源码，仍不发版、不部署。
