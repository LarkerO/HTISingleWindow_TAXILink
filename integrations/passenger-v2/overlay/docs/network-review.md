# 网络与跨域检查（2026-11-20）

后续本轮修复：配套后端已增加管理台“乘客端接入”，统一 HTTP / WebSocket 白名单并绑定各端 Portal 回调；下文“仅一个乘客 Origin”的描述是此前检查结果。生产并行配置见 [部署说明](production-deployment.md)。API 超时现为 25 秒以覆盖后端整次 18 秒报价预算，并对空响应与非 JSON 代理错误作明确提示；xRequest 的评估结论不变。

本次检查覆盖乘客端业务源码、TMUI 内置 xRequest，以及相邻后端源码和部署配置示例；未更改远端配置，也未进行真实登录、下单或支付操作。

## 现有请求链路

| 链路 | 检查结果 |
| --- | --- |
| 业务 HTTP | `lib/api.ts` 统一使用 `serviceUrl('/api…')`。Web 使用相对路径和 `withCredentials: true`；App 使用 HTTPS 根地址，并显式传递原生 Portal 会话 Cookie。 |
| 图片上传 | 通过同源 `/api/admin/media` 上传，保留 CSRF 与 App Cookie；由后端上传 COS，浏览器无需直接向 COS 发起跨域上传。 |
| BlueMap | 设置、瓦片和 3D WebApp 均通过 `/bluemap/`。3D 路线脚本需要访问 iframe，必须保持同源；只给地图开放 CORS 无法替代 iframe 的同源要求。 |
| 行程 WebSocket | Web 按当前页面协议选择 `ws`/`wss` 并使用当前主机 `/ws`；App 走轮询。开发代理到远端目前使用明文 `ws://host.bg7qvu.cn:8790`，这段代理到服务器的链路未加密。是否可改为 WSS 需确认远端确实提供 TLS 入口。 |
| Portal 与支付 | 使用页面导航、WebView 或系统浏览器。跨站导航本身不依赖 XHR CORS，但 OAuth 回调与 Cookie 所属主机必须一致。 |
| 静态资源 | 业务字体、品牌图片和登录视频来自本地静态资源；远端图片为展示用途，未发现业务代码将其读入 canvas。 |

已修复 API/BlueMap 开发代理缺少 `changeOrigin` 的问题；另将 App 外部浏览器登录分支改用 `serviceUrl`，避免 `appOrigin` 尾部 `/` 拼出 `//api/auth/start`。

## 仍需配套的环境配置

1. **Host 转换与 Origin 白名单是两个层面。** 后端 `Auth::csrf()` 对写请求校验 `APP_ORIGIN`/`ADMIN_ORIGIN`，`PassengerStream` 对 WebSocket 校验相同白名单。`changeOrigin` 修复上游 Host，并不会替代这些检查。用线上 Origin 配置测试时，本地 `http://localhost:5173` 和 `https://localhost:5173` 均被真实后端函数判为 `ORIGIN_INVALID`。协议、主机和端口须完全匹配。
2. **本地页面代理线上 API 不等于完整的本地 OAuth 环境。** `/api/auth/start` 使用后端配置的固定回调地址并设置主机 Cookie；如果授权从 localhost 开始、回调却落到线上域名，回调主机通常收不到 localhost 的 `tl_oauth` Cookie。开发应配套独立后端、Portal 回调与 Origin，或通过实际部署域名进行完整登录验证。后端目前仅支持一个乘客 Origin 和一个管理 Origin；v1/v2 并行部署也需核对。
3. **保持 Web 的 API、上传、WebSocket 和地图入口同源。** 后端 API 未配置通用 CORS 响应/预检；静态文件中 `*` 与凭证的示例是被注释的代码，当前未生效。直接改为浏览器跨域请求还涉及预检、凭证、Cookie 策略与 CSRF，单加 `withCredentials` 无法完成。
4. **生产部署与开发代理独立。** Vite 配置只影响开发；生产必须提供 `/api/`、`/ws`、`/bluemap/` 的反代。相邻仓库的旧乘客端和管理端代理目前指向本机 HTTP，未发现与远端 HTTPS 缺少 Host 转换相同的证书问题。

不建议通过伪造 HTTP/WebSocket Origin、关闭证书校验或开放全部来源解决上述环境不一致。

## xRequest 迁移评估

结论：可以作为 HTTP 传输层，但本项目内置版本目前不适合直接替换。它仍调用 `uni.request`，不会自行解决 CORS、反代、OAuth 或 WebSocket 问题。

收益：提供泛型结果、统一拦截器、请求记录、加载提示和任务管理；已随 TMUI 引入，无需新增请求库依赖；每次请求具有独立上下文，具备并发请求的基础。现有业务已经统一调用 `api<T>()`，可保留该接口，仅在其内部迁移，避免逐页改动。

### 当前源码中的阻碍

| 项目 | 具体行为与影响 |
| --- | --- |
| TLS 与凭证 | `uni.request` 写死 `sslVerify: false`、`withCredentials: false`，配置类型没有对应透传项。前者在支持该选项的平台关闭证书验证；后者影响 Web 跨域凭证，并非同源 Cookie 必然丢失。 |
| 同源地址 | 空 `hostUrl` 会直接拒绝请求，不能原样使用现有相对 URL。Web 可显式传入当前 origin；App 使用规范化后的部署 origin。 |
| 缓存写入 | 即使 `useCache: false`，成功后仍调用 `cacheManager.set()` 写入持久存储。订单、用户资料等会被额外持久化；不能仅靠关闭缓存选项规避。 |
| 缓存隔离 | key 删除查询字符串，对对象请求体只记录字段名，且未隔离 host、账号和凭证。不同分页、不同报价参数或不同账号可能命中相同数据；支持 POST 缓存也不适合直接用于交易接口。 |
| HTTP 成功判定 | 默认只接受单个 `200`，与现有封装接受全部 2xx 不同；201/204 需要调整成功策略。 |
| 异常收敛 | 响应钩子的 Promise 链缺少错误收敛；验证中 `after` 抛错产生未处理拒绝，外层请求持续 pending。 |
| 全局状态 | 单例共享 Header、授权开关和拦截器；API 与公共地图共用时，不能全局注入 Cookie/CSRF。`abort()` 会中止全部活动请求，不能直接当作页面级取消。 |
| 返回与交互 | 返回 `{data,statusCode,header,cookies}`，拒绝值也不是现有的 Error；默认加载/成功/错误提示全部开启、超时 6 秒，需要适配现有 12 秒超时、错误文案、401 清会话和页面提示。 |

### 建议路线

先修复 xRequest 的证书/凭证透传、缓存写入与隔离、2xx 成功策略和钩子异常处理，再通过 `api<T>()` 接入。逐次请求设置会话、CSRF 和幂等键；关闭自动成功提示；订单、报价、支付、用户资料默认不启用缓存。保留独立的上传、WebSocket、地图图片和 WebView 链路，这些不能由 xRequest 直接取代。

迁移后至少验证 Web、Android、iOS 的登录/退出、401、并发、超时、201/204、拦截器抛错、账号切换以及交易幂等；原生端需要实际运行验证。当前调用点已集中，适配成本主要来自库本身的修补和跨端回归，短期保留现有 `api<T>()` 的成本更低。

## 验证证据

- 上一轮通过实际 Vite 代理读取地图配置/设置、8 张可见 PNG 瓦片以及 3D HTML/入口脚本，保留了 TLS 校验。
- 本轮对真实 `Auth::csrf()` 函数执行无数据库、无业务写入的 Origin 检查。
- 对本地 xRequest 源码按 Web 条件编译后，以模拟传输验证了空 host 拒绝、TLS/凭证标志、禁用缓存仍写入、查询/请求体缓存碰撞、201 拒绝及 `after` 抛错悬挂。
- 对登录组件按 Web/App 条件分别转译，并以模拟导航执行真实登录函数，验证地址、路径分隔符与 `returnTo` 编码。
- 未进行本轮原生打包或真实账号授权回归。

参考：[uni-app x 请求 API](https://doc.dcloud.net.cn/uni-app-x/api/request.html)、[Vite 5 开发代理](https://v5.vite.dev/config/server-options#server-proxy)。
