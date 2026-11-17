# TAXILink 城市出行平台

与 `TAXILink_MOD` 独立的业务仓库，面向单个 Minecraft 服务器、单个 MTR 维度。

| 应用     | 技术                                          | 开发地址                |
| -------- | --------------------------------------------- | ----------------------- |
| 总后端   | webman 2 / Workerman 5、PDO、MySQL 8、Redis   | `http://localhost:8787` |
| 运营管理 | React 19、Vite、Ant Design 6、ProComponents 3 | `http://localhost:5174` |
| 乘客端   | Vue 3、Vite、TDesign Mobile                   | `http://localhost:5173` |

ProComponents 使用 npm 实际发布且支持 Ant Design 6 的 `3.1.15-9` 版本，锁定在 lockfile。管理端遵循 Ant Design Pro 的布局与组件体系，使用 Vite 构建。

## 选择运行方式

| 用途 | 数据库与登录 | 入口 |
| --- | --- | --- |
| 本地演示 | SQLite、演示账号、模拟模组 | [快速体验](#快速体验) |
| 本机联调 | SQLite、HydCraft Portal、真实 SM2 模组网关 | [本机测试安装](docs/DEPLOYMENT.md#本机测试安装) |
| 正式部署 | MySQL、Redis、HTTPS、HydCraft Portal | [生产安装](docs/DEPLOYMENT.md#生产安装) |

Windows PowerShell 生成安装码：在项目根目录安装 Composer 依赖后，运行 `php .\backend\bin\console.php install-code`。使用与启动后端相同的 Windows 账号。安装码适用于未配置的实例；请先在单独目录准备全新实例，再验证浏览器安装流程。

## 快速体验

需要 PHP 8.2+（PDO SQLite/MySQL、curl、mbstring、OpenSSL）、Composer、Node.js 22.12+。连接真实模组还需要 OpenSSL 3 命令行；Linux 需要 pcntl、posix。

```powershell
npm ci
cd backend
composer install
Copy-Item .env.example .env
php bin/console.php migrate
php bin/console.php seed
php windows.php
```

在两个新终端分别运行：

```powershell
npm run dev:passenger
npm run dev:admin
```

Linux 从 `backend` 运行 `php start.php start`。首次运行请访问上述 **localhost** 地址，与示例 Origin 配置保持一致。

本地示例配置启用演示模式，使用 SQLite、模拟模组及可主动点击的演示账号登录。演示账号具有平台管理员及两个企业的管理身份。演示轨迹和地点仅用于体验。**真实 Portal OAuth 登录与演示登录是独立入口，正式环境没有演示账号入口。支付始终仅演示，不调用游戏经济系统。**

演示行程约 8 秒到达上车点，可点击“我已上车”发车；未点击时约 20 秒自动发车。数据库在 `backend/runtime`，不纳入 Git。重复 seed 不覆盖已有配置。

## 已实现的业务

- 平台管理员可在系统日志查看 WebSocket 连接结果、指令下发时间、请求 ID 和总耗时。

- 游客浏览与选点，需登录操作弹出提示，由用户主动前往 Portal；账号无需绑定 Minecraft。
- Portal 授权码 + PKCE S256、state 及 Cookie 绑定，服务器换取 opaque token 并调用 userinfo，创建两小时本地会话；不把上游令牌发给浏览器。
- 平台创建企业、签发一次性邀请；账号支持跨企业、多角色成员身份。Portal 的全局角色不自动成为本平台权限。
- 企业独占车辆中心；每个中心多个单轨专用车厂；车型配置、企业统一费率、虚拟点/组与多站台映射、运营范围批量增删。
- 对运营范围、人数与三段连通性询价，留存路线和费率快照，价格明细包含接客与回库超程调度费。
- 多企业候补由单调度进程协调，按轨道接客距离尝试车厂，成功后撤销其他候补。请求超时时保留原单号与原参数对账，不向其他中心盲目重派。
- 订单幂等、状态日志、乘客订单分页、WebSocket 快照及 HTTP 轮询降级、手动与原模组自动发车。
- 实际放行开始计费，抵达下车点停止计时。确认乘客全部下车后结束订单并结算，车辆回库由运营端继续跟踪。无人乘车费默认 0，可配置。
- 一口价、实时计费及先付/后付/预付补退的演示付款和退款记录；未付预付款订单不会派车。
- 轨迹地图支持双指缩放、拖动、滚轮、回原点、定位车辆；X/Z 投影独立，便于后续接入底图。

## 检查

检查仅在本地执行，仓库不配置远端 CI。

```powershell
npm test                 # 领域测试、数据库初始化与 Portal OAuth 回归
npm run build           # 两端类型检查与生产构建
npm run test:api        # 需先启动演示后端
npx playwright install chromium
npm run test:e2e        # 需先启动后端和两个 Vite 开发服务
```

浏览器测试截图位于 `artifacts`。接口测试会在本地演示数据库创建并取消测试订单；领域测试完全使用内存数据库。

## 项目文档

- [部署与权限指南](docs/DEPLOYMENT.md) · 安装模式、PowerShell 命令、Portal 回调、Linux 权限、模组接入与升级。
- [架构与业务规则](docs/ARCHITECTURE.md) · 调度、订单、结算与多端关系。
- [接口说明](docs/API.md) · HTTP API 与 WebSocket。

测试安装和生产安装均使用真实业务逻辑。支付功能仅供演示。

## BlueMap 地图

乘客首页、行程详情和管理端订单、车厂及站台配置默认显示 BlueMap 2D 底图。平台管理员在 **BlueMap 设置** 中切换地图 ID，并控制两端是否可打开原生 3D 世界视图。部署和瓦片地址见 [BlueMap 部署说明](docs/DEPLOYMENT.md#bluemap-底图)。

本地回归使用 `npm run build` 和 `npm test`。
