# HTTP 与 WebSocket

API 前缀 `/api`，JSON 请求/响应。同源部署，HttpOnly Cookie `tl_session` 鉴权；写请求还须携带 `/me` 返回的 `X-CSRF-Token`。错误格式 `{error:{code,message}}`，使用对应 HTTP 状态码。

待发布的多端接入：`GET/POST /admin/passenger-clients` 仅平台管理员可用，POST 为 `{origins:["https://passv2.example.com"]}`。返回 `{origins,primary}`；原 APP_ORIGIN 和可选 PASSENGER_ORIGINS 始终保留。新乘客端登录使用 `/auth/start?clientOrigin=<当前源>&returnTo=<本地路径>`，需在 Portal 登记该源的 `/api/auth/callback`，来源在服务端 state 中绑定；HTTP 与 WebSocket 共用来源白名单。

`GET/POST /admin/media/settings` 仅平台管理员可用，POST 为 `{bucket,region,publicUrl,secretId,secretKey}`。凭据留空保留现有值；返回只含公开配置、`configured`、`secretIdConfigured`、`secretKeyConfigured`、`maxBytes`，不返回凭据。数据库保存加密配置后即时生效，优先于旧环境变量。

`POST /quotes` 继续接受 `{pickupId,dropoffId,passengers}`，在原 `{id,expires,offers,unavailable}` 上增加 `complete`、`warning`。整次网关预览等待预算 18 秒、最多 4 路并发；超过预算返回已完成报价及提示，全部失败时返回 JSON 错误。成功路线缓存 20 秒，计费仍每次计算；下单/派车校验保持有效。建议客户端超时至少 25 秒，并处理空响应、非 JSON 网关错误和部分报价。

| 方法       | 路径                                | 用途                                                          |
| ---------- | ----------------------------------- | ------------------------------------------------------------- |
| GET        | `/health`                           | 服务状态、演示标识                                            |
| GET        | `/points?q=`                        | 公开虚拟点及点组                                              |
| GET        | `/auth/start?destination=admin`     | 用户主动跳转 OAuth；默认回乘客端                              |
| GET        | `/auth/callback`                    | state、Cookie、PKCE 换令牌及 userinfo                         |
| POST       | `/auth/demo`                        | 仅演示模式的主动登录                                          |
| GET        | `/me`                               | 当前用户、CSRF、企业成员身份                                  |
| POST       | `/auth/logout`                      | 注销本地会话                                                  |
| POST       | `/quotes`                           | `{pickupId,dropoffId,passengers}`                             |
| POST       | `/orders`                           | `{quoteId,companyIds,vehicleId?}`；必需 `Idempotency-Key`     |
| GET        | `/orders?page=1`                    | 本人订单，30 条/页                                            |
| GET        | `/orders/{id}`                      | 本人订单与轨迹、遥测                                          |
| GET        | `/orders/{id}/events`               | 本人订单动态、支付记录                                        |
| POST       | `/orders/{id}/depart`               | 已在上车点时确认发车                                          |
| POST       | `/orders/{id}/cancel`               | 提交取消请求                                                  |
| POST       | `/orders/{id}/pay`                  | 演示付款/退款，不连接货币系统                                 |
| POST       | `/invitations/accept`               | `{token}`，接受企业邀请                                       |
| GET        | `/admin/{resource}?companyId=`      | 管理列表                                                      |
| POST/PATCH | `/admin/{resource}/{id?}`           | 新增/编辑管理对象                                             |
| GET        | `/admin/catalog/{kind}`             | 模组 depots/sidings/stations/platforms，自动按 revision 翻页  |
| GET        | `/admin/points/{id}/platforms`      | 具体地点的轨道映射                                            |
| GET/POST   | `/admin/companies/{id}/scope`       | 查范围；写 `{action:"add"或"remove",pointIds:[],groupIds:[]}` |
| POST       | `/admin/companies/{id}/fare`        | 企业管理员更新 `{fare}`                                       |
| POST       | `/admin/companies/{id}/invitations` | 平台签发 `{role:"admin"或"dispatcher"}` 邀请                  |
| DELETE     | `/admin/memberships/{id}`           | 平台移除成员身份                                              |
| GET        | `/admin/orders/{id}/events`         | 企业订单动态                                                  |
| POST       | `/admin/orders/{id}/control`        | 企业调度 `{action:"emergency"或"resume"或"cancel"}`           |

resource：`companies`、`centers`、`vehicles`、`point_groups`、`points`、`memberships`、`orders`、`payments`、`audit_logs`。仅前五类支持写入，且各自验证权限。管理订单、审计和支付列表当前最多返回最近 200 条；乘客端订单列表支持全量翻页。

客户端连接同源 `/ws`，反向代理到 8790，携带 Cookie；服务校验 Origin 与会话。每两秒推送 `{type:"orders.snapshot",orders:[...],sampledAt:秒}`，仅包含登录用户自己的最近 30 笔订单，超出部分通过分页 API 查询。支持客户端 `ping` 字符串及 `{type:"pong"}` 响应。连接失效或发送积压关闭，行程页降级为每四秒 HTTP 查询。

所有 MTR ID 必须使用十进制字符串，负数合法，禁止传 JS 数字。金额为整数分；Unix 时间除原始模组遥测/日志外均为秒。

## BlueMap 设置

- `GET /api/map-settings` 返回公开的 `mapId` 和 `allow3d`。初次部署时 `mapId` 为 `null`，前端使用构建默认地图。
- `POST /api/admin/map-settings` 接受 `mapId` 字符串和 `allow3d` 布尔值。需要平台管理员会话及 CSRF 请求头。

## UnionCashier

- `POST /api/orders/{id}/pay`：本人订单发起付款，响应订单视图及可选 `checkoutUrl`，前端直接跳转。
- `POST /api/orders/{id}/payment-sync`：查询并确认到账、处理终态多付款退款，返回最新订单。需会话和 CSRF，不接受前端传入金额或付款状态。
- `GET/POST /api/admin/cashier`：平台管理员读取/保存总商户。
- `GET/POST /api/admin/cashier/{companyId}`：企业管理员或平台管理员读取/保存公司商户，调度员无权读取密钥配置。
- 配置字段：`enabled`、`origin`、`merchant`、`keyId`、`privateKey`、`platformKey`、`environment`、`returnUrl`。读取不返回 API Key 与私钥，写入留空保留旧值。
