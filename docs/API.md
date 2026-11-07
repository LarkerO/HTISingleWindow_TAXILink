# HTTP 与 WebSocket

API 前缀 `/api`，JSON 请求/响应。同源部署，HttpOnly Cookie `tl_session` 鉴权；写请求还须携带 `/me` 返回的 `X-CSRF-Token`。错误格式 `{error:{code,message}}`，使用对应 HTTP 状态码。

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
| GET        | `/orders/{id}/events`               | 本人订单动态、演示支付记录                                    |
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
