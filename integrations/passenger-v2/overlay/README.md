# TAXILink 乘客端

移动端 uni-app x 项目。App 启用 Vapor、Bytecode 和 TypeScript，另提供 H5 构建供手机浏览器使用。项目内置用户提供的 TMUI4X 组件源码。入口用 Vue 的 `install` 对象兼容 HBuilderX 5.26，并修复了 `x-modal` 和 `x-drawer` 打开动画期间的关闭操作。

品牌名、金额、预计时间和订单计数优先使用本地 D-DIN。H5 的全局字体栈按 D-DIN、阿里巴巴普惠体、系统无衬线字体排列。App 将普惠体应用到各 TMUI 文本、输入和标题组件，单独指定的 D-DIN 数字和品牌样式优先。普惠体采用官方 3.0 常规体，H5 打包 5.26 MB WOFF2，App 打包 7.37 MB OTF。两个平台各自只包含一种普惠体格式。[阿里巴巴字体官网](https://www.alibabafonts.com/#/font) · [Datto D-DIN 发布文件镜像](https://github.com/amcchord/datto-d-din)

## 运行

2026-11-20 待发布修复已配套多乘客端 Portal 回调与来源登记、报价超时/无效响应提示、部分报价提示，以及 3D 渲染健康检测和 2D 恢复。完整保留数据升级方案在相邻业务仓库 `docs/UPGRADE_PENDING_20261120.md`。本轮仅本地修复与验证，不发布新版。

1. 在 HBuilderX 中打开本目录和它的 `manifest.json`，选择运行到 Android、iOS 或浏览器。原生 Portal 会话插件需要自定义调试基座或正式包。
2. H5 开发代理在 `vite.config.ts` 中配置，当前使用 TAXILink API `https://console.taxi.htisw.bg7qvu.cn`、行程推送 `ws://host.bg7qvu.cn:8790` 和 BlueMap `https://map.oxygen.hydcraft.cn`。API 和 BlueMap 的远端 HTTPS 代理须保留 `changeOrigin: true`，使上游 Host 和 TLS SNI 使用目标域名，避免将 `localhost` 传给上游导致证书域名校验失败。连接本地后端时可改为对应的本机端口，并将后端的 `APP_ORIGIN` 对应到当前 H5 地址。修改代理配置后需重新启动 HBuilderX 的 Web 运行。
3. App 在 `lib/config.ts` 中配置部署后 H5 共用的 HTTPS 根地址。Portal 在 Android/iOS 通过 WebView 完成授权，再由 App 校验会话。鸿蒙的 Portal 授权和后续操作在浏览器中继续。演示登录由后端的 `demo` 开关提供。
4. 部署 H5 时，将站点根目录指向构建产物并保留 `/api/`、`/ws` 和 `/bluemap/` 代理。沿用原站的 SPA 回退规则，以便 Portal 跳转和旧版企业邀请链接可进入页面。

跨域、开发登录环境与 xRequest 迁移注意事项见 [网络请求检查与评估](docs/network-review.md)。

生产环境出现 `/api/` 或 `/bluemap/` 的 Nginx 404 时，参见 [v2 站点生产反代配置](docs/production-deployment.md)。已提供与当前域名配套的完整配置和 `node scripts/check-deployment.mjs` 只读检查脚本。

## 流程

- 首页选择和交换上下车点。报价页支持 1–16 人、车型筛选、费用明细与多企业下单。
- 行程页显示实时位置、路线、费用、接客和抵达预估，支持确认发车及取消。H5 使用 WebSocket 并保留 HTTP 查询，App 每四秒查询进行中的行程。
- 收银台复用后端 UnionCashier 下单与到账确认接口，H5 跳转付款，App 打开系统浏览器；返回后刷新付款结果，支持预付、原路退款和补差额。演示模式仍不扣款。账号页支持退出及接受企业邀请。
- 首页、选车和行程详情使用 `x-float-drawer`。选车抽屉左侧为车型，右侧为企业报价与多选。站点搜索使用 `x-sticky`。
- 2D BlueMap 使用瓦片，仅显示低精度 PNG 上半部的颜色数据，并裁去接边像素；下半部的高度/光照数据不作为地图显示。支持触摸手势、桌面鼠标拖动和以光标为中心的滚轮缩放。3D 采用同源 BlueMap WebApp 标记层，随相机渲染订单路线和车辆位置。标记只在当前视图内生成，已行驶路线为灰色，前方路线带白色方向箭头。3D 开关由 `/api/map-settings` 控制。

地图页使用满幅 BlueMap，状态栏下方悬浮左侧操作按钮和居中的模糊标题。App 的「我的」可扫描企业邀请二维码，接受邀请仍需手动确认。扫码和模糊插件需要包含对应模块的自定义调试基座。

平台管理台的「本机国密自检」使用 `x-crypto-s` 验证 SM3 已知向量。现有业务 HTTP 接口沿用 HTTPS、会话 Cookie 和 CSRF。SM2 双向签名用于后端网关与游戏模组之间的连接，相关密钥由这两个端点管理。

## 手机运营管理台

待发布运营修复：地图纯图标按钮扩大为 44px 并修正居中；管理订单支持开门保持停车、关门、清除空车和核验占用回库车，权限与实际执行均由后端/模组校验。图片上传在多个服务已配置时可选择 COS、OSS、七牛或 AWS S3；配置入口在网页管理台“对象存储”。完整说明随业务仓库 docs/OPERATIONS_PENDING_20261120.md 提交，本轮不部署。

平台管理员、企业管理员与企业调度员可从「我的 → 运营管理台」进入，复用乘客端登录会话和后端的企业授权。企业管理员可维护所属企业的车辆中心、车型、运营范围和计费。调度员可查看派车状态与订单动态，并按所属企业的权限提交调度指令。平台管理员可管理企业、站点和分组、车厂授权、企业成员及 BlueMap 设置，并查看支付、操作日志和系统日志。订单详情使用 BlueMap 和 `x-float-drawer`，调度指令提交前有二次确认。

## 图片与公告中心

车型和公司图片由管理台上传到腾讯云 COS，选车和行程页读取报价/订单快照中的图片。个人页新增公告中心；下单、订单、个人页通过 `x-notice` 显示对应位置公告，独立详情页通过 `x-cmarkdown` 渲染。手机运营管理台支持图片上传、Markdown 公告编写/预览、发布/下架、位置选择、列表可见性和登录限制。COS 密钥仅配置在 webman 后端；本地项目无需密钥。

## 统一收银台

在运营管理台选择“收银台商户”，全平台配置总商户，选择运营公司配置专属商户；未配置专属商户时使用总商户。回跳 URL 填写 H5 站点的 `/payment-return`，并在收银台登记该来源。商户密钥仅保存在后端；车费按整游戏币向上取整。App 从浏览器返回时查询到账结果，亦可手动刷新。
