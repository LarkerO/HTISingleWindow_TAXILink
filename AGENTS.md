# 仓库约定

提交遵循 Conventional Commits：`type(scope): 中文描述`，正文使用中文。
同一任务先完成本地实现与验证，再集中推送；两个仓库分别提交和推送。
默认直接在 `main` 开发和推送，不另建任务分支，除非用户明确要求。
本仓库包含 backend（webman）、admin（React + Vite + Ant Design + ProComponents）、passenger（Vue 3 + Vite + TDesign Mobile）。
优先在业务后端解决调度、权限、支付问题，仅在现有协议确实缺失能力时修改 TAXILink_MOD。
演示模式保留演示支付；正式环境通过 UnionCashier 签名接口下单、查询确认与退款，禁止仅凭浏览器回跳或本地点击入账。
验证以本地测试、类型检查和构建为准。不要添加或启用 GitHub Actions 等远端 CI，除非用户明确要求。
