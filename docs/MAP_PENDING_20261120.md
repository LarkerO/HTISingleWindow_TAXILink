> 历史内测记录；0.5.2 已进入正式发布流程，当前升级要求以 RELEASE_0.5.2.md 为准。文中“不发布/待发布”描述的是当时阶段。

# BlueMap 与主页在线位置（仅提交源码，待内测）

## 2D 与组件

console 原 SVG 没有拖拽事件；现补充指针捕获、拖拽、缩放、复位，使用 Ant Design Button/Switch/Alert。Vue v1 使用 Ant Design Vue 地图控制，指针坐标通过 SVG 逆矩阵计算，修正宽高比留白与 CSS 缩放导致的偏移。

PNG 上半部是颜色，下半部是高度/光照。共享渲染器裁出颜色，使用 G/B 高度编码、R 方块光照计算 2D 预览阴影，默认启用并可切换纯色对比；透明度保留。这是昼间 2D 近似，不是完整 3D shader。TMUI 仍只显示颜色，没有增加高度/光照。编码依据：[BlueMap 官方 LowresFragmentShader](https://github.com/BlueMap-Minecraft/BlueMap/blob/master/common/webapp/src/js/map/lowres/LowresFragmentShader.js)。失败瓦片留空，缓存有数量上限。

## 3D 排查结果与边界

只读检查发现 console 当前 mapId 为 world，配置代理可返回；真实 BlueMap 页面同时请求归档地图配置，出现配置未完成、长期“正在加载地图”的状态。新增嵌入文档只暴露所选地图配置，避免等待其他归档地图；保留官方 WebApp、摄像机参数和资源代理。健康检测在文档准备后运行，支持 srcdoc 同源消息校验，失败/超时恢复 2D。

**真实上游 3D 渲染尚未验收通过**：只读浏览器复验仍观察到加载停滞，不能将健康检测、单地图隔离或构建通过视为真实场景已恢复。当前不修改、不部署远端 BlueMap；后续内测需继续核查上游地图资源响应与设备 WebGL，观察成功渲染后再关闭此问题。

## 在线位置

Vue v1 和 TMUI 主页登录后每 10 秒查询当前地图 live/players.json，仅匹配用户名完全一致、foreign=false 的唯一记录。无记录或接口失败，回退到 GET /api/me/map-position。离线、过期、退出登录、切换账号或页面销毁时清理标记，不显示其他玩家。

用户已确认 **Portal 用户名 = Minecraft 游戏名**，当前按此精确匹配；数据库原来没有游戏 UUID。用户名不同不会自动猜测绑定。若实际采用 UUID 绑定，应先明确可信字段，再补映射，不能拿 Portal subject 任意当游戏 UUID。

新 API 仅登录可用，从服务器会话确定用户名，不能通过请求参数查询其他玩家；限频、no-store，不把坐标存进数据库。新模组指令 player.position 在 Minecraft 主线程每秒采集在线快照，只返回配置维度匹配、5 秒内的坐标，离线清除。旧模组未识别指令时返回 unavailable，前端仍可使用 BlueMap 或显示底图。接口依据：[BlueMap 官方在线数据](https://github.com/BlueMap-Minecraft/BlueMap/blob/master/common/src/main/java/de/bluecolored/bluemap/common/live/LivePlayersDataSupplier.java)。

## 保留数据与验证

没有新增数据库列或清表迁移；保留数据库、installation/密钥、runtime、游戏存档和 taxilink-state，沿用前两批维护窗口升级方案。不重新 install、不 seed、不删除数据卷。新后端配旧模组可降级，模组位置后备能力需同步更新模组；回滚源码保留最新业务数据。

本轮覆盖瓦片颜色/数据分离、实际拖拽、Ant Design 控制、3D 失败恢复、在线玩家匹配、离线/其他维度隔离及既有业务回归。v2 完整变更文件同步到 integrations/passenger-v2/overlay。本轮不创建标签、Release，不部署。
