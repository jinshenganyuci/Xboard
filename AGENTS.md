# Xboard 二开协作说明

本文件适用于整个仓库。先读本文件，再读 [第三方订阅功能说明](docs/zh/development/external-subscriptions.md) 和 [云端开发交接](docs/zh/development/cloud-development.md)。以下是项目约束；用户在当前任务中的明确要求优先。

## 仓库与当前基线

- Fork 为 `https://github.com/jinshenganyuci/Xboard`，二开基线分支为 `feat/third-party-subscriptions`。新任务先检查分支、HEAD 和工作区，不要假定默认分支包含这些功能，也不要覆盖已有未提交修改。
- 本次交接记录于 2026-11-04。最近已发布的功能版本是 `external-v1.0.5`，源码提交 `86a03f80699a91f970a2a45705c54655aa317f66`。此记录是基线，不代表未来始终为最新版。
- 发布镜像为 `ghcr.io/jinshenganyuci/xboard`，使用 `Dockerfile.external` 和 `.github/workflows/publish-external-image.yml`。上游 README 的 `compose` 分支和普通 Dockerfile 不是此二开的发布入口。
- 日常代码修改和基础测试适合云端开发环境；完整容器验收和镜像发布沿用 GitHub Actions。生产站点及其数据仍由部署服务器持有。

## 必须保持的行为

- 第三方节点仅下发给存在、未封禁、有关联套餐且未到期的用户；永久有效套餐沿用原规则。第三方节点不检查本地流量额度，自建节点保持原有资格规则。
- 仅下发启用源中已勾选、上游仍存在且可完整转换的节点。目前支持 VLESS、Trojan、Hysteria2；输入为 URI、Base64 URI、Clash YAML，输出为通用订阅、Mihomo / Clash Meta、Sing-box。不要丢弃关键连接参数来假装支持节点。
- 保留上游名称，不添加 `〔外部〕` 或其他来源后缀；重名时才追加唯一序号。所有符合资格的用户共用同一组选择及上游凭据，不引入额外流量控制。
- 成功同步按完整快照处理，物理删除已消失的节点及勾选。有效空快照清空节点；拉取或解析失败保留旧快照。重新出现的节点默认不勾选，凭据更新保持同一节点的原选择。
- 镜像内独立同步 worker 每 60 秒扫描，源成功同步满 5 分钟才拉取，通常约 5–6 分钟更新；失败有 60 秒冷却。不要依赖管理员打开页面来同步。
- 已启用、已选择且支持的节点通常每小时测活一次，全站最多并发两个探测。首次明确失败转灰色待复查，5 分钟后备用目标复查；确认失败且控制请求正常才离线。成功在线，基础设施异常未知，超过 2 小时的结果未知。
- 测活必须通过真实节点凭据代理 HTTPS 204 请求。TCP 端口开放、直接访问目标成功都不能判节点在线。保留固定目标、公共地址校验、DNS 固定、超时、Redis 锁、租约及版本条件写入，防止重复检测或旧结果覆盖新配置。
- 打开用户面板、更新订阅及内容相同的同步不额外测活；离线状态只影响面板展示，不改变已勾选节点的下发资格。
- 默认主题蓝色在线、红色离线、灰色待复查或未知，并显示检测时间。保留原生节点行为。健康状态资源由 `DefaultThemeHealthAsset` 校验并生成，不直接改上游编译 bundle，不强行覆盖自定义主题。

## 代码入口

| 范围 | 入口 |
| --- | --- |
| 管理接口与界面 | `app/Http/Controllers/V2/Admin/ExternalSubscriptionController.php`、`resources/views/admin/external-subscriptions.blade.php` |
| 拉取、解析、同步和下发 | `app/Services/ExternalSubscription/`、`app/Protocols/` |
| 测活状态与真实代理探测 | `HealthService.php`、`ProbeTransport.php`，均在上述服务目录 |
| 用户面板节点接口 | `app/Http/Controllers/V1/User/ServerController.php` |
| 主题资源生成及展示 | `app/Support/DefaultThemeHealthAsset.php`、`public/assets/external-subscriptions/`、`theme/Xboard/dashboard.blade.php` |
| 独立后台进程 | `.docker/external-subscriptions/worker.sh`、`supervisor.conf`，以及 `app/Console/Commands/` 下的同步和测活命令 |
| 数据表 | `database/migrations/2026_10_21_000001_create_external_subscriptions_tables.php`、`2026_11_03_000001_add_external_node_health.php` |

## 开发与测试

- 使用 PHP 8.2+、Composer 的锁定开发依赖、SQLite 和独立 Redis。运行 `composer install`，不要用 `composer update` 解决环境问题。完整安装、启动及浏览器测试步骤见云端开发交接文档。
- `phpunit.external.xml` 固定测试库为项目相对路径 `.docker/.data/testing.sqlite`，测试会重建表。当前 SQLite 配置会拼接项目路径，不要改成绝对路径或 `:memory:`。
- 即使测试使用 `CACHE_DRIVER=array`，测活锁仍显式使用 Redis。测试使用 Redis DB 14/15 和测试前缀，必须指向独立测试 Redis，不能连接生产 Redis。
- 命令从仓库根目录运行。下列命令假设依赖与测试 Redis 已准备好。

```bash
mkdir -p .docker/.data
touch .docker/.data/testing.sqlite
vendor/bin/phpunit -c phpunit.external.xml
```

主题或面板状态变更还需生成实际主题资源并运行浏览器回归；安装好 Playwright / Chromium 后执行以下命令。源码 checkout 必须具备 `theme/Xboard` 的固定主题资源。

```bash
php -r 'require "app/Support/DefaultThemeHealthAsset.php"; App\Support\DefaultThemeHealthAsset::build(getcwd());'
node tests/browser/external-node-health.cjs
```

生成的 `public/assets/external-subscriptions/xboard-dashboard-health-v1.js` 是构建产物，不要提交。交接文档还提供输出到临时目录的方式；优先使用该方式避免工作区残留，不能删除原本就存在且来源不明的文件。

真实协议回归需要 Docker Engine / Compose 和 OpenSSL。先按交接文档构建待测代码的本地镜像，再运行；不能拿旧 `latest` 镜像的通过结果代表当前修改。

```bash
XBOARD_HEALTH_TEST_IMAGE=xboard-external:candidate .github/scripts/run-health-smoke.sh
```

- 完整镜像验收还包括全新安装、真实订阅 HTTP 和无人访问时自动删除上游节点，入口及容器配置在发布 workflow 和 `.github/scripts/`。写入型 smoke 脚本只能用于一次性空测试实例。
- 当前发布 workflow 只由 `external-v*` 标签触发，尚未自动运行 PHPUnit 和浏览器测试，也不是普通提交或 PR 的完整 CI。不能把未执行、被跳过或环境不支持的检查报告为通过。
- 按变更选择检查。文档修改执行差异及链接检查即可，不因此启动 Docker；业务修改执行相关回归，发布前完成适用的基础、浏览器和容器验收。报告实际结果及未覆盖项，不把历史测试数当作本次结果。

## 资源和清理

- 用户的 Mac 上，Docker / Colima 虚拟机总内存上限为 **2 GiB**。测试容器硬上限为 `1536m`，`memswap_limit` 同为 `1536m`；安装、构建及测试错开执行，不同时保留多套运行中的实例。
- 不因文档整理、阅读代码或查询发布状态启动 Colima。确需本地容器测试时，使用已核对的专用 profile 和 Docker context，不能误启动默认的大内存 profile。
- 本地测试结束，包括失败退出，清理本次临时容器、网络、卷、证书、日志及仅为本次创建的镜像或缓存；停止本任务启动的 Colima profile，并用 `colima list` 确认。不要仅停容器而留下虚拟机运行。
- 清理范围仅限本次临时资源。已有演示数据和用户挂载需保留；不要使用无差别 `docker system prune --volumes` 或删除工作区中的未知数据。

## 数据与升级

- 第三方订阅源和节点存于原 Xboard 数据库的新增表；源 URL 和连接配置由原 `APP_KEY` 加密。新增健康字段也在节点表中，Redis 用于缓存和锁。
- 不提交或输出真实 `.env`、订阅 URL/token、节点凭据、管理员账号文件、SQLite 数据库、私钥或生产备份。云端测试创建合成数据，不复制本机私密文件。
- 已有站点升级保留 `.env`、`APP_KEY`、数据库和所有原有数据挂载，仅按发布文档换镜像；不要再次运行安装，不要重新生成生产密钥。
- 回退使用升级前固定镜像及兼容的数据状态，保留附加表；不要执行 `migrate:rollback` / `xboard:rollback`。过去验证过的版本组合不等于所有未来迁移都可以直接回退，新增迁移必须评估并说明兼容范围。

## 提交与发布

- 沿用用户授权的提交、推送和发布范围。仅改文档不打发布标签、不更新镜像；用户已明确授权推送或发布时，完成相应检查后继续执行，无需重复询问。
- `Dockerfile.external` 重用按 digest 固定的官方镜像依赖，sing-box 同样固定 digest。不要在发布镜像里重新构建 vendor autoloader 或升级依赖；若确需改依赖，先调整运行时和兼容性验证。workflow 对 `composer.json` / `composer.lock` 有基线差异门禁。
- 镜像发布使用新的、未使用过的 `external-v*` 标签触发专用 workflow，生成 AMD64 / ARM64 镜像。不要移动已有版本标签，不绕过验证直接推 `latest`。
- 保留的上游 `.github/workflows/docker-publish.yml` 会在 `master` / `new-dev` 推送或手动触发时构建另一条发布路径，其中 `master` 会写同仓库的 `latest`。不要用它发布本二开；未来合并这些分支或调整默认分支前，先解决两个发布流程的标签冲突。
- workflow 会先推版本镜像，再对该镜像执行清洁数据、启动、HTTP、自动同步删除及真实协议健康检查；只有全部成功才更新 `latest`。看到版本标签已存在不等于发布验收成功。
- 发布后核对 workflow 成功、源码 revision、镜像 digest、双架构及版本标签与 `latest` 一致，再提供升级命令。镜像升级使用 Compose，不使用后台源码更新按钮或 `update.sh`。
- 后续若增加普通 PR CI，应复用现有隔离测试并保持发布门禁；不要借一次文档交接顺带改动运行逻辑或上线版本。
