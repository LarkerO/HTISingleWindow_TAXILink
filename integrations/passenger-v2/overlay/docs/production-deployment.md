# v2 乘客站生产反代

## 本次线上故障

2026-11-20 对 `https://passv2.taxi.htisw.bg7qvu.cn` 进行无登录只读检测：

| 地址 | 响应 |
| --- | --- |
| `/` | 200，乘客端 HTML |
| `/api/map-settings`、`/api/me` | OpenResty HTML 404 |
| `/bluemap/`、`/bluemap/maps/world/settings.json` | OpenResty HTML 404 |
| `https://console.taxi.htisw.bg7qvu.cn/api/map-settings` | 200，JSON |
| `https://map.oxygen.hydcraft.cn/maps/world/settings.json` | 200，JSON |

用户提供的 server 配置只有静态站点与 TLS 配置，没有上述服务的反代 location。这是生产路由缺失；请求未到达 API 或地图上游，尚未进入业务 Origin/CORS 校验。`vite.config.ts` 的 `server.proxy` 只在本地开发时生效，不会随静态打包发布到 OpenResty。

## 应用配置

1. 备份 1Panel 中此站点的当前配置。
2. 使用 [完整站点配置](../deploy/passv2.nginx.conf) 替换此站点的整个 server 块。文件保留了用户提供的域名、root、日志、证书和 TLS 设置，并补上 API、上传、WS、BlueMap 和前端路由回退。不要把它再嵌套进已有 server。
3. 确认 OpenResty **容器内**的 CA 文件路径：示例使用 `/etc/ssl/certs/ca-certificates.crt`；若实际使用 `/etc/ssl/cert.pem`，替换 `proxy_ssl_trusted_certificate` 的路径。保持上游证书验证开启。
4. 在 1Panel 执行配置检查，通过后保存并重载；也可以在实际 OpenResty 容器内执行 `nginx -t`，通过后执行 `nginx -s reload`。本地没有服务器登录信息，尚未代为应用或在云端执行这两条命令。
5. 本机运行 `node scripts/check-deployment.mjs`，确认公开 API、地图设置、PNG 瓦片和 3D 入口全部通过。此修改只涉及服务器配置，无需重新打包前端。

配置使用现有公网 API `console.taxi.htisw.bg7qvu.cn`，以及 BlueMap `map.oxygen.hydcraft.cn`；API 代理不带 URI 后缀，从而保留 `/api/...`，BlueMap 代理以 `/` 结尾，从而移除 `/bluemap/` 前缀。HTTPS 上游显式设置 Host 与 TLS SNI；地图上游不接收乘客站 Cookie/Authorization。`^~` 避免未来的静态资源正则规则截走地图 PNG/JSON/JS 请求。

行程推送沿用当前开发配置中的 `host.bg7qvu.cn:8790`。浏览器到站点使用同源 WSS，站点到此上游仍为 WS；若已有私网或 WSS 入口，可改用实际入口。该链路需要云服务器能访问此端口，且成功登录后才能验证后端的会话与 Origin 校验。当前公开读取检测不验证这部分。

## 登录与写接口的配套配置

反代恢复后，还应核对后端实际配置（安装模式一般为 `backend/installation/config.json`，手动配置为环境变量/`.env`）：

```dotenv
APP_ORIGIN=https://taxi.htisw.bg7qvu.cn
ADMIN_ORIGIN=https://console.taxi.htisw.bg7qvu.cn
PORTAL_REDIRECT_URI=https://taxi.htisw.bg7qvu.cn/api/auth/callback
APP_SECURE_COOKIE=1
```

升级本轮配套后端并迁移数据库后，在管理台“乘客端接入”加入 `https://passv2.taxi.htisw.bg7qvu.cn`；同时在 Portal 的同一个应用登记 `https://passv2.taxi.htisw.bg7qvu.cn/api/auth/callback`。主乘客端和管理端的配置及回调均保留。管理台添加地址即时生效，也可通过可选 `PASSENGER_ORIGINS` 环境变量添加额外来源（更改环境变量需要重启）。

Vue v1 与 v2 现在可并行使用同一后端，后续乘客端同样通过管理台登记。写请求与 WebSocket 共用白名单；新前端的 Portal 登录携带 clientOrigin 并在服务端绑定到一次性 state，回调设置当前站点的会话 Cookie。同一 Portal 账号共用既有用户和订单，但各站点独立登录。升级应先备份和迁移后端，再更新管理端及两个乘客端；不要把 Origin 改写为管理站地址来绕过校验。

无需新增通配 CORS 响应，也不要把 Web API 地址改成浏览器直连管理站；保持同源 API、Cookie 和地图 iframe，才能沿用当前登录与 3D 路线机制。

参考：[Vite server 配置](https://vite.dev/config/server-options)、[Nginx proxy_pass](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_pass)、[Nginx 上游 SNI](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_ssl_server_name)。
