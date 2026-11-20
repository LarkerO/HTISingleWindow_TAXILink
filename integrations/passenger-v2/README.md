# v2 乘客端源码覆盖层

v2PassengerFrontend 是独立 HBuilderX 工程，目前不在 Git 仓库中。本目录保存本轮修改涉及的完整源文件，供 GitHub 审查、恢复和同步使用；不含 TMUI 依赖、构建产物或安装包。

将 overlay/ 内的内容按相对路径复制到已有 v2PassengerFrontend 工程根目录后，用该工程现有 HBuilderX 环境构建。不要将此覆盖层当成完整、可独立构建的工程。每轮修改需同步此覆盖层与本地 v2 工程。

本轮仅同步源代码，不创建版本标签、Release 或执行部署。
