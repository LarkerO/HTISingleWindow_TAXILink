# TAXILink 城市出行平台

与 `TAXILink_MOD` 独立的业务仓库，面向单个 Minecraft 服务器、单个 MTR 维度。

| 应用     | 技术                                          | 开发地址                |
| -------- | --------------------------------------------- | ----------------------- |
| 总后端   | webman 2 / Workerman 5、PDO、MySQL 8、Redis   | `http://localhost:8787` |
| 运营管理 | React 19、Vite、Ant Design 6、ProComponents 3 | `http://localhost:5174` |
| 乘客端   | Vue 3、Vite、TDesign Mobile                   | `http://localhost:5173` |

ProComponents 使用 npm 实际发布且支持 Ant Design 6 的 `3.1.15-9` 版本，锁定在 lockfile。管理端遵循 Ant Design Pro 的布局与组件体系，使用 Vite 构建。

## 本地运行

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

- 游客浏览与选点，需登录操作弹出提示，由用户主动前往 Portal；账号无需绑定 Minecraft。
- Portal 授权码 + PKCE S256、state 及 Cookie 绑定，服务器换取 opaque token 并调用 userinfo，创建两小时本地会话；不把上游令牌发给浏览器。
- 平台创建企业、签发一次性邀请；账号支持跨企业、多角色成员身份。Portal 的全局角色不自动成为本平台权限。
- 企业独占车辆中心；每个中心多个单轨专用车厂；车型配置、企业统一费率、虚拟点/组与多站台映射、运营范围批量增删。
- 对运营范围、人数与三段连通性询价，留存路线和费率快照，价格明细包含接客与回库超程调度费。
- 多企业候补由单调度进程协调，按轨道接客距离尝试车厂，成功后撤销其他候补。请求超时时保留原单号与原参数对账，不向其他中心盲目重派。
- 订单幂等、状态日志、乘客订单分页、WebSocket 快照及 HTTP 轮询降级、手动与原模组自动发车。
- 实际放行开始计费，下车阶段停止时长计费；先补查模组日志和轨迹，再最终结算，避免断线丢事件误判无人乘车。无人乘车费默认 0，可配置。
- 一口价、实时计费及先付/后付/预付补退的演示付款和退款记录；未付预付款订单不会派车。
- 轨迹地图支持双指缩放、拖动、滚轮、回原点、定位车辆；X/Z 投影独立，便于后续接入底图。

## 检查

```powershell
npm test                 # 独立内存数据库领域测试
npm run build           # 两端类型检查与生产构建
npm run test:api        # 需先启动演示后端
npx playwright install chromium
npm run test:e2e        # 需先启动后端和两个 Vite 开发服务
```

浏览器测试截图位于 `artifacts`。接口测试会在本地演示数据库创建并取消测试订单；领域测试完全使用内存数据库。

## 对接与部署

详见 [架构与业务规则](docs/ARCHITECTURE.md)、[接口说明](docs/API.md)、[真实环境接入](docs/DEPLOYMENT.md)。前端编译产物分别位于 `admin/dist`、`passenger/dist`，由反向代理提供 SPA 回退。

当前已完成本地模拟链路验证；真实 Portal 客户端配置、SM2 模组连接、实际 MTR 地图、MySQL/Redis 及正式域名需要在目标环境联调。真实货币接口尚未接入。
