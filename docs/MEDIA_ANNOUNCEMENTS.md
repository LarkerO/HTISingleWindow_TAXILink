# COS 图片与公告中心

本次由 webman 统一提供图片和公告服务，React 管理端、Vue 乘客端以及本地 uni-app x v2 乘客端共用接口。游戏模组协议不变。

## 配置与升级

升级前备份数据库；后端启动迁移会为企业和车型补充 `image_url` 列，并创建 `announcements` 表。兼容 SQLite 和 MySQL，可重复执行。

在 `backend/.env` 或服务进程的环境变量中填写：

```dotenv
COS_BUCKET=your-bucket-1250000000
COS_REGION=ap-guangzhou
COS_SECRET_ID=填写服务端访问密钥ID
COS_SECRET_KEY=填写服务端访问密钥
COS_PUBLIC_URL=https://images.example.com
```

`COS_PUBLIC_URL` 可留空，使用桶的 HTTPS 域名。填写 CDN 域名时，根路径应映射到同一个桶。重启后端后，在管理端「COS 图片存储」查看配置状态，再上传一张图片验证连接。

图片通过后端签名 PUT 上传，前端不接触 COS 密钥。配置图片桶或对应 `taxilink/` 前缀公开读取，写权限仅授予服务端；服务端账号只需目标桶 `taxilink/*` 的 `cos:PutObject` 权限。上传使用腾讯云 [COS XML API 签名](https://cloud.tencent.com/document/api/436/7778) 和 [PUT Object](https://cloud.tencent.com/document/product/436/7749)。由于浏览器只向同源后端上传，无需为上传配置桶 CORS。

允许 PNG、JPEG、WebP、GIF，单张最多 5MB，长宽最多 8192px；服务端检测图片内容，不接受 SVG。文件写入随机对象路径，不覆盖旧对象。更换/清除图片只修改引用，旧文件保留以兼容历史订单快照；需要清理时应先核对历史引用。

同步更新 Nginx 配置：普通 API 请求上限 256KB，`/api/admin/media` 单独允许 6MB、60 秒超时。参考 `deploy/nginx.conf.example`。其他网关也应同步调整。

## 图片维护

- 平台管理员在「运营企业」上传公司标志。
- 平台管理员或所属企业管理员在「车型管理」上传车型示意图；调度员无上传和修改权限。
- 编辑时可预览、替换、清除图片，也可填写已有 HTTPS 图片地址。
- 报价返回 `companyImageUrl`、`vehicleImageUrl`，创建订单时进入快照。选车和行程详情使用该快照；旧记录没有图片时保留文字信息。
- v2 手机运营管理台也支持公司/车型图片上传及修改，使用已有 Cookie、CSRF 和企业授权。

## 公告维护及展示规则

平台管理员通过「公告中心」新建和编辑 Markdown 公告，支持正文图片上传、预览、发布和下架。v2 手机运营管理台也提供公告编写和预览。

| 设置 | 行为 |
| --- | --- |
| 发布 | 关闭时作为草稿或下架，不对乘客开放详情 |
| 页面通知栏 | 下单页、订单页、个人中心分别选择，可多选或不选 |
| 在公告列表显示 | 独立控制个人中心进入的公告列表，不影响通知栏入口 |
| 需要登录查看 | 未登录时不返回标题、列表条目或正文；详情链接会要求登录 |

公告按更新时间和 ID 倒序，列表每页 30 条。通知栏每个位置最多展示最新 30 条。列表仅返回摘要字段，不返回正文。

两个乘客端都可从个人中心的「公告中心」进入列表。点击通知栏或列表进入独立详情页。v2 使用 `x-notice` 展示通知、`x-cmarkdown` 渲染正文。Vue 乘客端和 React 预览使用 MarkdownIt（关闭原始 HTML）并经 DOMPurify 清理。

公告为统一平台公告，不引入企业公告或用户定向投放。无需展示的公告可以下架；不提供物理删除。

## 接口

| 方法与路径 | 权限/用途 |
| --- | --- |
| `GET /api/admin/media/settings` | 平台管理员，仅返回配置状态和公开信息 |
| `POST /api/admin/media` | multipart：`file`、`purpose`（company/vehicle/announcement）、`companyId`（车型必填） |
| `GET /api/admin/announcements` | 平台管理员，含草稿和正文 |
| `POST /api/admin/announcements` | 平台管理员，创建 |
| `PATCH /api/admin/announcements/{id}` | 平台管理员，编辑/发布/下架 |
| `GET /api/announcements?page=1` | 按登录状态过滤的公告列表 |
| `GET /api/announcements?placement=booking` | 页面通知栏，另支持 orders/profile |
| `GET /api/announcements/{id}` | 已发布详情，按公告配置检查登录 |

公告写入字段为 `title`、`markdown`、`placements`（字符串数组）、`published`、`listed`、`login_required`（后三项为 JSON 布尔值）。标题最多 120 字，正文最多 200KB UTF-8。所有写请求沿用登录会话、CSRF 和审计日志。

## 验证

```powershell
npm test
npm run build
# 启动两个前端开发服务后运行；本机需有 Playwright 浏览器
npx playwright test tests/e2e/content.spec.ts
```

领域测试覆盖公告权限、可见性、登录限制、下架、无效参数、图片权限、伪装文件、图片快照和重复迁移。浏览器测试模拟 API，验证通知栏至详情/列表、Markdown 内容隔离和管理端保存；真实 COS 网络上传需配置桶和密钥后联调。

v2 为独立的本地 HBuilderX 工程，本次仅本地修改和验证，不纳入此仓库 Git。
