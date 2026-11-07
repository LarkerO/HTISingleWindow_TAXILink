# 第三方代码

`backend` 基于 workerman/webman 官方脚手架，原 MIT 许可保存在 `backend/LICENSE`。

`backend/app/process/Sm2Wire.php` 来自本项目对应的 TAXILink_MOD 仓库 PHP 示例，用于兼容既有 TAXILink-v2 / SM2-SM3 信封协议。该实现保持独立，不向浏览器暴露私钥。

前端组件及其依赖的许可见各 npm 包；依赖版本由 package-lock.json 和 backend/composer.lock 锁定。
