# 云端开发、测试与发布交接

本文记录本 fork 的开发约束和可复用测试入口。接手时以当前源码、工作流和测试结果为准，不把历史通过记录当成本次改动已经通过。功能操作见[第三方订阅下发](external-subscriptions.md)。

## 仓库与已交付版本

- 仓库为 `jinshenganyuci/Xboard`，当前二开分支为 `feat/third-party-subscriptions`。创建云端任务后先确认分支，不假定默认分支已包含这些改动。
- 当前交付基线为 `external-v1.0.5`，功能提交为 `86a03f80699a91f970a2a45705c54655aa317f66`。
- 镜像为 `ghcr.io/jinshenganyuci/xboard:external-v1.0.5`；`latest` 是可变标签，不用于长期标识测试对象。
- v1.0.5 多架构索引 digest 为 `sha256:04e21a9edb6ff95bbfcc367a1a318126a0d3b7e551baf3d4dbf5b6cae74688d0`，包含 `linux/amd64` 和 `linux/arm64`。
- [v1.0.5 发布任务](https://github.com/jinshenganyuci/Xboard/actions/runs/41666121872)通过后才更新 `latest`。以后发布应记录新的提交、标签、digest 和工作流结果，不覆盖旧版本标签。

## 已实现的行为与不可随意改变的约束

| 功能 | 当前行为 |
| --- | --- |
| 用户资格 | 已注册、未封禁、已关联套餐且未过期，支持永久套餐；第三方节点忽略本地已用流量，自建节点沿用原规则 |
| 节点下发 | 管理员按源勾选；只下发启用源中已勾选、仍存在且受支持的节点；所有符合资格的用户使用同一组节点 |
| 格式与协议 | 输入 URI、Base64 URI、Clash YAML；支持 VLESS、Trojan、Hysteria2，保留认证和传输参数；输出通用 URI、Mihomo/Clash Meta、Sing-box 等现有适配格式 |
| 名称 | 保留上游名称，不加 `〔外部〕`；重名仅追加 ` #2` 等序号 |
| 上游同步 | 独立进程每 60 秒扫描，成功快照满 5 分钟才刷新；通常 5–6 分钟同步一次，不依赖有人访问面板 |
| 删除与空快照 | 成功同步物理删除消失节点和勾选；有效空订阅清空节点；失败保留旧快照；消失后重新出现默认不勾选 |
| 节点测活 | 启用源中已勾选且受支持的节点，正常每小时一次，两个 slot 限制最多并发 2；新节点分散首次检测 |
| 状态判断 | 用真实代理凭据请求固定 HTTPS 204 目标；成功在线，首次明确失败待复查，5 分钟后备用复查确认失败才离线；检测异常或超过 2 小时的结果为未知 |
| 状态展示 | 默认 Xboard 主题蓝色在线、红色离线、灰色待复查/未知，显示最近检测时间；自定义主题需自行适配状态字段 |
| 下发与测活分离 | 离线不自动取消勾选、不阻止下发；刷新页面和订阅不触发探测；未变化的同步不使测活缓存失效 |
| 安全边界 | URL 和节点配置由 `APP_KEY` 加密；保留上游拉取与节点探测的公网地址校验、DNS 固定、防重入和日志脱敏；直接访问备用目标只能否决离线，不能据此判在线 |

本功能不转发用户流量，不统计第三方节点的每用户用量，也无法撤销用户已经下载的上游凭据。不要把“面板停止下发”描述为“断开第三方连接”。

## 云端环境准备

云端开发使用仓库代码和合成测试数据。生产 `.env`、`APP_KEY`、数据库、上游订阅地址、管理员账号和本地 Docker 数据卷都不需要上传。

先在云端检查实际分支和干净状态，再安装依赖：

```bash
git branch --show-current
git status --short
git submodule update --init --recursive
```

基础测试需要 PHP 8.2 或兼容的更高版本、Composer、SQLite 驱动、phpredis 和隔离的 Redis 服务。其他 PHP 扩展按锁文件实际安装要求补齐，使用 `composer check-platform-reqs` 检查；不要通过 `--ignore-platform-reqs` 掩盖缺失扩展。浏览器测试另需 Node.js、Playwright 和 Chromium。真实镜像验收另需 Docker Engine、Compose、Buildx、OpenSSL 及拉取 GHCR 镜像的能力。

下面只在独立开发 checkout 中执行，不覆盖已有站点配置：

```bash
test -f .env || touch .env
mkdir -p .docker/.data
touch .docker/.data/testing.sqlite

CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
  COMPOSER_MEMORY_LIMIT=768M \
  composer install --prefer-dist --no-interaction --no-scripts
composer check-platform-reqs
CACHE_DRIVER=array QUEUE_CONNECTION=sync SESSION_DRIVER=array \
  php artisan package:discover --ansi
```

保留锁文件，使用 `composer install`，不要为消除提示执行 `composer update`。当前发布镜像复用固定官方运行时的 vendor，不能直接把云端新生成的 vendor 打包进去。依赖安装失败时记录缺失项并修复环境；不得因此跳过检查或宣称测试通过。

把安装依赖的步骤配置为云端环境的安装流程，把启动隔离 Redis、验证服务就绪的步骤配置为启动流程。运行测试前检查没有会覆盖测试配置的 `bootstrap/cache/config.php`，并确认测试用 Redis 没有连接生产实例。云端环境本身的 Docker 和网络能力需要在创建时实测，不以“已经创建环境”替代项目验收。

## 测试入口与覆盖范围

所有命令从仓库根目录执行。不同测试使用独立、可销毁的数据库，不能指向线上站点或保留的演示实例。

| 检查 | 入口 | 当前自动执行情况 |
| --- | --- | --- |
| PHP 单元与功能回归 | `vendor/bin/phpunit -c phpunit.external.xml` | 手动运行，发布工作流尚未包含 |
| 默认用户主题状态 | `node tests/browser/external-node-health.cjs` | 手动运行，发布工作流尚未包含 |
| 镜像纯净性、全新安装、HTTP 接口 | `.github/scripts/smoke-external-image.php` | 发布工作流创建一次性容器后执行 |
| 无 Web 服务时自动同步与删除 | `.github/scripts/smoke-external-worker.php` | 发布工作流在隔离网络中执行 |
| 真实 VLESS、Trojan、Hysteria2 测活 | `.github/scripts/run-health-smoke.sh` | 发布工作流执行，也可验证本地候选镜像 |

截至 v1.0.5，已有通过记录为 PHPUnit 149 个测试 / 1001 个断言、218 项 HTTP 检查、真实三协议测活与默认主题浏览器检查。这是该版本的历史基线，后续增加测试后数量可能变化。发布工作流目前仅由 `external-v*` 标签触发，普通提交和 PR 尚无上述全量自动检查；补 PR CI 是后续开发事项，不是已经完成的功能。

### PHP 测试

启动仅供本任务使用的 Redis 后执行。以下示例假定测试 Redis 监听本环境 `127.0.0.1:6379`，按实际测试服务地址替换：

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 \
  vendor/bin/phpunit -c phpunit.external.xml
```

`phpunit.external.xml` 强制使用相对路径 `.docker/.data/testing.sqlite`、合成加密密钥、Redis DB 14/15 和测试前缀。测试会重建数据库表，Redis 服务仍需隔离；仅使用 `CACHE_DRIVER=array` 不足以替代 Redis，因为测活锁等代码显式使用 Redis。环境如果配置了 `REDIS_URL`、`DATABASE_URL` 或缓存的 Laravel 配置，应先在测试环境中移除这些覆盖，不要把修正写回生产 `.env`。

覆盖解析与协议转换、资格、鉴权、源编辑和选择、上游节点删除、空快照、失败保留、定时刷新、状态转换、并发 slot、修订号竞争、SSRF 限制、凭据脱敏及主题生成。单元测试中的模拟探测成功不代表真实节点联网成功。

### 默认用户主题浏览器测试

脚本使用真实生成的默认主题 bundle 和合成 API 响应，阻断其他网络请求，无需真实订阅或管理员登录。Playwright 应在云端工具目录安装，浏览器依赖准备好后运行；`PLAYWRIGHT_MODULE` 可指向工具目录中的 `playwright` 包，使用系统 Chromium 时另设 `CHROME_BINARY`。

以下 Bash 示例将生成 bundle 放在临时目录，不改动已提交的主题文件：

```bash
(
  set -eu
  XBOARD_BROWSER_TMP=$(mktemp -d)
  trap 'rm -rf "$XBOARD_BROWSER_TMP"' EXIT
  php -r 'require "app/Support/DefaultThemeHealthAsset.php"; file_put_contents($argv[1], App\Support\DefaultThemeHealthAsset::compile(file_get_contents("theme/Xboard/assets/umi.js")));' \
    "$XBOARD_BROWSER_TMP/dashboard.js"
  XBOARD_HEALTH_BUNDLE="$XBOARD_BROWSER_TMP/dashboard.js" \
    node tests/browser/external-node-health.cjs
)
```

脚本会检查自建节点显示不变、四种第三方状态颜色、检测时间以及悬停、键盘焦点、点击和 Esc 行为。上游默认 bundle 校验失败时应审查兼容性，不应删除 SHA 校验后强行注入。

根 `AGENTS.md` 也给出了直接调用 `DefaultThemeHealthAsset::build(getcwd())` 的快捷命令。它会生成 `public/assets/external-subscriptions/xboard-dashboard-health-v1.js`，当前该文件不在 Git 忽略列表中；仅当确认是本次生成的测试产物时清理，不要把它加入提交。上述临时目录方法无需清理源码目录中的文件。

### 候选镜像与真实协议测试

在本次任务专用 Docker 环境中构建本机架构候选镜像，测试前暂停其他测试实例。Mac 本地 Docker 虚拟机总内存上限为 2 GiB，不能通过增加内存解决测试失败。

```bash
docker build -f Dockerfile.external -t xboard-external:candidate .
XBOARD_HEALTH_TEST_IMAGE=xboard-external:candidate \
  .github/scripts/run-health-smoke.sh
```

候选标签只是示例，应使用本任务独有且未被其他项目使用的标签。测试发布版本时将 `XBOARD_HEALTH_TEST_IMAGE` 替换为明确版本或 digest；测试当前改动不能拉取旧镜像后当作当前源码已验证。

脚本要求全新 Compose 项目，默认项目名为 `xboard-release-health`。如需指定新名字，设置以 `xboard-release-health` 开头的 `XBOARD_HEALTH_TEST_PROJECT`。已有该项目容器时脚本拒绝复用，不能删掉保护绕过。

测试使用 `internal` 网络、合成代理服务、一次性证书和仅容器内信任的 CA，不挂载生产数据，不访问真实上游节点。`11.254.233.2` 是仅限这个隔离测试网络的地址，不应复制到生产配置。容器硬限制为 1536 MiB，swap 上限同为 1536 MiB。脚本验证正确凭据上线、错误凭据失败复查、恢复上线、目标异常、结果过期及未选节点不检测等行为，并通过退出钩子删除 Compose 容器、网络和临时证书。

HTTP 与自动删除的完整启动步骤在 `.github/workflows/publish-external-image.yml` 内，复用时保持新安装实例、网络隔离和资源限制。三个 `smoke-*.php` 脚本均有破坏性合成数据写入，只能在脚本要求的新一次性实例内设置 `RELEASE_SMOKE_ALLOW_WRITE=1`，不能直接对业务站点执行。

测试完成检查退出码、容器状态、OOM 与内存，再清理本次候选镜像和构建残留。保留其他任务的数据和缓存；Mac 本地测试最后必须关闭本次启动的 Colima 并确认状态，详见[清理要求](external-subscriptions.md#测试结束与清理)。

## 发布要求

1. 先完成代码审查和与变动相关的 PHP、浏览器、协议测试；涉及迁移、运行时或启动流程时追加升级/回退验证。文档改动仅需校验文档，不为了交接更新 `latest`。
2. 检查工作树及待提交文件，确保 `.env`、真实订阅链接、数据库、客户端配置、证书和日志未进入提交或 Docker 构建上下文。
3. 提交并推送二开分支，在明确需要发布版本时创建新的 `external-v*` 标签并推送。不要复用已有标签；仅推送 `feat/third-party-subscriptions` 分支不会触发当前二开镜像发布流程。
4. 等待 `Publish third-party subscription image` 全部成功。它先构建 AMD64/ARM64 版本镜像，然后对发布的实际 digest 做纯净性、全新安装、HTTP、自动删除和真实协议检查，最后才更新 `latest`。版本镜像可能已推送但后续检查失败，此时不得将其当作正式通过版本。
5. 发布后核对 `latest` 与新版本标签的 digest 一致、架构包含 AMD64/ARM64，记录 Actions 链接和确切版本。当前 Actions 容器验收运行在 Ubuntu AMD64；多架构构建成功不等于两种架构都做过运行验证，报告时区分构建与实测。

工作流和 `Dockerfile.external` 固定基础运行时为 `ghcr.io/cedar2025/xboard@sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676`，探测核心为官方 sing-box 1.14.3 的固定多架构 digest。`composer.json`、`composer.lock` 与基线 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256` 必须一致；如果确实需要更改依赖，应单独更新运行时策略并验证，不删除工作流门禁。

仓库仍保留上游 `.github/workflows/docker-publish.yml`，它在 `master`、`new-dev` 推送或手动触发时使用普通 Dockerfile 发布到同一个镜像仓库，其中 `master` 会写 `latest`。本二开只使用 `publish-external-image.yml` 发布。未来合并到这些分支或调整发布方式前，先处理上游 workflow 的标签冲突，避免跳过二开验收覆盖 `latest`；这次文档交接不修改工作流。

当前镜像通过专用 Dockerignore 允许列表打包应用代码，不复制本地 vendor。新增目录或运行时文件后检查 Dockerfile 和允许列表是否一起更新。默认主题健康状态资产由构建过程生成，不能只验证源码存在而忽略镜像是否带入。

## 存储、升级与回退边界

第三方数据在现有应用数据库中的 `external_subscriptions`、`external_subscription_nodes` 两表，测活字段也在节点表内。Redis 承担锁和缓存，不应作为订阅配置的唯一备份。原始 URL 和节点参数依赖原 `APP_KEY` 解密，数据库和 `.env` 必须配套备份和恢复。

生产升级保留原 Compose 挂载、数据库、Redis、主题、插件和 `APP_KEY`，只替换镜像并按[已有站点升级说明](external-subscriptions.md#已有站点使用发布镜像)操作。现有站点不运行 `xboard:install`，不执行数据库 rollback 命令。回退保留新增表和测活字段，使用升级前记录的固定镜像；新迁移、上游基础版本和插件兼容性需要针对实际部署重新验证，不能承诺任何历史镜像都安全回退。

云端开发环境的成功测试不表示已经修改生产站点。完成任务时分别说明代码推送、镜像发布、生产部署各自是否实际完成，以及本次测试和清理结果。
