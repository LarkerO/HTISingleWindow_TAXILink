# TAXILink 总后端

启动、配置与接入说明见 [项目 README](../README.md) 及 [部署文档](../docs/DEPLOYMENT.md)。

1Panel 推荐使用 [专用 Compose 部署流程](../docs/DEPLOYMENT_1PANEL.md)。镜像以 www-data（UID/GID 33:33）运行；使用本目录旧版 `docker-compose.yml` 的绑定挂载时，先创建 runtime、installation、keys，确保前两者归属 33:33 且可写，keys 中的密钥允许该用户读取。旧配置需要现成的 `.env` 和手动密钥，不适用于全新浏览器安装。
