# 开发、测试与发布交接

本文记录本 fork 的开发约束和可复用测试入口，沿用原文件名以保持已有链接。当前用户已改用 Mac 主控和 Debian 编译虚拟机，不要求创建 Codex 云端环境。接手时以当前源码、任务记录和测试结果为准，不把历史通过记录当成本次改动已经通过。功能操作见[第三方订阅下发](external-subscriptions.md)与[全局 DNS 证书配置](node-dns.md)。

Mac 只进行源码编辑、调度、轻量语法/差异检查及预览；Docker 构建、完整测试、网页服务在 Debian 编译虚拟机运行。GitHub 推送和镜像上传也只从 Debian 执行，且当前任务必须有明确授权。不要因旧文档中的 Colima 或 Actions 示例绕过用户最新规范。

## 仓库与已交付版本

- 仓库为 `jinshenganyuci/Xboard`，当前二开分支为 `feat/third-party-subscriptions`。接手后先确认分支、工作区和 Debian 任务状态，不假定默认分支已包含这些改动。
- 2026-11-05 已发布 `external-v1.0.6`，功能提交为 `e19809b5875d1d6b24b2f903cbfcdc16d9743598`，包含 AnyTLS 和延迟功能。[发布任务](https://github.com/jinshenganyuci/Xboard/actions/runs/42023529028)全部成功，已核对 `latest` 与版本标签的多架构 digest 均为 `sha256:bf4648121cd151d57d0657209cc4d4dbaac91f52c65192d3fe4f280904018251`，包含 AMD64 / ARM64，两个架构的 OCI revision 均指向上述功能提交。
- 前一历史交付基线为 `external-v1.0.5`，功能提交为 `86a03f80699a91f970a2a45705c54655aa317f66`。
- 该历史版本镜像为 `ghcr.io/jinshenganyuci/xboard:external-v1.0.5`；`latest` 是可变标签，不用于长期标识测试对象。
- v1.0.5 多架构索引 digest 为 `sha256:04e21a9edb6ff95bbfcc367a1a318126a0d3b7e551baf3d4dbf5b6cae74688d0`，包含 `linux/amd64` 和 `linux/arm64`。
- [v1.0.5 发布任务](https://github.com/jinshenganyuci/Xboard/actions/runs/41666121872)通过后才更新 `latest`。以后发布应记录新的提交、标签、digest 和工作流结果，不覆盖旧版本标签。

### v1.0.6 历史验收记录（2026-11-05）

- 该版本代码 PHPUnit：165 个测试、1419 个断言通过；两个浏览器脚本通过，默认主题使用候选镜像生成的实际 v2 bundle。
- 本机 ARM64 候选镜像：285 项 HTTP 检查；隔离 Compose 中 VLESS、Trojan、Hysteria2、AnyTLS 真实 HTTPS 测活与延迟，以及失败复查、恢复、过期和无额外探测行为通过。
- 用户提供的 11 节点私密订阅：3 VLESS、3 Trojan、5 AnyTLS 全部识别；四种格式的名称、凭据和连接参数验证通过；Sing-box 配置检查通过；11 个节点各一次真实代理 HTTPS 204 请求全部成功。因本机 DNS 返回 Fake-IP，该一次性实连脚本使用验证 TLS 的固定公共 DoH 解析，仍执行原公网地址校验；发布代码没有放宽校验或加入该测试解析器。此结果只描述测试时从本机出发的联通情况。
- 独立临时 SQLite 验证从 v1.0.5 表结构添加可空延迟字段，原密文和勾选不变，新字段默认 NULL，旧列集合的读、插入、更新仍成功。本次未重新执行旧镜像整体回退；回退兼容范围不能由此扩展到任意历史版本。
- 发布的 AMD64 digest 通过工作流的全新安装、HTTP、无人访问自动删除和真实四协议测活，再更新 `latest`。ARM64 本地实测对象为同源码候选镜像。
- 本地专用 Colima 上限 2 GiB，容器内存及含 swap 上限均为 1536 MiB，没有 OOM。临时容器、网络、镜像、构建缓存和私密订阅副本已删除，原演示数据保留；三个 Colima profile 均确认停止。未部署生产站点。

上述为旧版本当时的记录，保留其原执行环境。2026-11-13 全局 DNS 功能新增的代码、浏览器脚本与升级夹具见下文；这里没有将旧结果计作新功能通过，也没有新增发布版本号。

## 已实现的行为与不可随意改变的约束

| 功能 | 当前行为 |
| --- | --- |
| 用户资格 | 已注册、未封禁、已关联套餐且未过期，支持永久套餐；第三方节点忽略本地已用流量，自建节点沿用原规则 |
| 节点下发 | 管理员按源勾选；只下发启用源中已勾选、仍存在且受支持的节点；所有符合资格的用户使用同一组节点 |
| 格式与协议 | 输入 URI、Base64 URI、Clash YAML；支持 VLESS、Trojan、Hysteria2、AnyTLS，保留认证和传输参数；输出通用 URI、Mihomo/Clash Meta、Sing-box 等已适配格式，客户端须支持相应协议 |
| VLESS 包编码 | `packetEncoding=none` 按禁用额外包编码处理；通用 URI 保留 `none`，Mihomo / Sing-box 使用对应禁用值，不得静默改成默认 XUDP |
| 名称 | 保留上游名称，不加 `〔外部〕`；重名仅追加 ` #2` 等序号 |
| 上游同步 | 独立进程每 60 秒扫描，成功快照满 5 分钟才刷新；通常 5–6 分钟同步一次，不依赖有人访问面板 |
| 删除与空快照 | 成功同步物理删除消失节点和勾选；有效空订阅清空节点；失败保留旧快照；消失后重新出现默认不勾选 |
| 节点测活 | 启用源中已勾选且受支持的节点，正常每小时一次，两个 slot 限制最多并发 2；新节点分散首次检测 |
| 节点延迟 | 复用每小时测活的代理 HTTPS 请求，记录请求耗时；不包含探测进程启动时间，不新增定时任务，也不是用户本机 ping |
| 状态判断 | 用真实代理凭据请求固定 HTTPS 204 目标；成功在线，首次明确失败待复查，5 分钟后备用复查确认失败才离线；检测异常或超过 2 小时的结果为未知 |
| 状态展示 | 管理页及用户节点 API 返回 `health_status`、`last_check_at`、`latency_ms`；仅在线且结果未过期时返回有效延迟，其余为 `null`。默认 Xboard 主题蓝色在线、红色离线、灰色待复查/未知，显示有效延迟与最近检测时间；自定义主题需自行适配字段 |
| 下发与测活分离 | 离线不自动取消勾选、不阻止下发；刷新页面和订阅不触发探测；未变化的同步不使测活缓存失效 |
| 安全边界 | URL 和节点配置由 `APP_KEY` 加密；保留上游拉取与节点探测的公网地址校验、DNS 固定、防重入和日志脱敏；直接访问备用目标只能否决离线，不能据此判在线 |
| 全局 DNS | 保存单组提供商、通知邮箱和加密环境变量；新建 DNS-01 节点可默认引用，旧节点保持独立配置；证书域名逐节点确认 |
| DNS 密钥与更新 | 管理读取只返回脱敏元数据，空输入保留，换提供商需新凭据，revision 防覆盖；仅节点鉴权配置下发解析密钥，更新通知只影响引用节点 |

本功能不转发用户流量，不统计第三方节点的每用户用量，也无法撤销用户已经下载的上游凭据。不要把“面板停止下发”描述为“断开第三方连接”。

## Debian 开发环境准备

开发使用仓库代码和合成测试数据。生产 `.env`、`APP_KEY`、数据库、上游订阅地址、管理员账号和 DNS Token 都不需要上传。Mac 到 Debian 按本次差异单向同步，核对提交及修改一致，避免两边同时编辑同一份源码。

使用凭据 MCP 当次记录核验编译虚拟机，建立独立 `/srv/buildlab/tasks/xboard/<任务ID>/`。记录 `task.json`、资源归属、代码版本和日志入口；按用户全局规范检查过期任务候选。先在 Debian checkout 检查分支和工作区，再安装依赖：

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

保留锁文件，使用 `composer install`，不要为消除提示执行 `composer update`。当前发布镜像复用固定官方运行时的 vendor，不能直接把开发环境新生成的 vendor 打包进去。依赖安装失败时记录缺失项并修复环境；不得因此跳过检查或宣称测试通过。

共享下载缓存可复用，任务的可变构建目录和测试数据保持独立。运行测试前检查没有会覆盖测试配置的 `bootstrap/cache/config.php`，并确认测试用 Redis 没有连接生产实例。长测试优先使用 Debian 的 `/srv/buildlab/control/buildlab-job.py` 托管；SSH 断开后先查询原任务，不重复启动。预览由 Debian 持续托管并提供已从 Mac 验证的局域网入口，用户确认结束后再清理。

## 测试入口与覆盖范围

所有命令从仓库根目录执行。不同测试使用独立、可销毁的数据库，不能指向线上站点或保留的演示实例。

| 检查 | 入口 | 当前自动执行情况 |
| --- | --- | --- |
| PHP 单元与功能回归 | `vendor/bin/phpunit -c phpunit.external.xml` | 手动运行，发布工作流尚未包含 |
| 默认用户主题状态与延迟 | `node tests/browser/external-node-health.cjs` | 手动运行，发布工作流尚未包含 |
| 第三方订阅管理页延迟与交互 | `node tests/browser/external-admin-latency.cjs` | 手动运行，发布工作流尚未包含 |
| 全局 DNS 后端与资源适配 | `tests/Unit/NodeDns/`、`tests/Feature/NodeDns/` | 已纳入 `phpunit.external.xml`；在 Debian 执行 |
| 全局 DNS 设置页 | `node tests/browser/node-dns-settings.cjs` | 合成 API 浏览器回归；在 Debian 执行 |
| 原后台 DNS 节点表单 | `node tests/browser/node-dns-form.cjs` | 需生成实际 `AdminDnsAsset` bundle；在 Debian 执行 |
| 旧站升级、HTTP 配置和重建持久化 | `.github/scripts/run-node-dns-upgrade.sh` | 一次性合成站点；在 Debian 执行，不能替代任意旧镜像回退验证 |
| 镜像纯净性、全新安装、HTTP 接口 | `.github/scripts/smoke-external-image.php` | 发布工作流创建一次性容器后执行 |
| 无 Web 服务时自动同步与删除 | `.github/scripts/smoke-external-worker.php` | 发布工作流在隔离网络中执行 |
| 真实 VLESS、Trojan、Hysteria2、AnyTLS 测活与延迟 | `.github/scripts/run-health-smoke.sh` | 发布工作流执行，也可验证本地候选镜像 |

截至 v1.0.5，已有通过记录为 PHPUnit 149 个测试 / 1001 个断言、218 项 HTTP 检查、真实三协议测活与默认主题浏览器检查。这是该版本的历史基线，后续增加测试后数量可能变化。发布工作流目前仅由 `external-v*` 标签触发，普通提交和 PR 尚无上述全量自动检查；补 PR CI 是后续开发事项，不是已经完成的功能。

### PHP 测试

启动仅供本任务使用的 Redis 后执行。以下示例假定测试 Redis 监听本环境 `127.0.0.1:6379`，按实际测试服务地址替换：

```bash
REDIS_HOST=127.0.0.1 REDIS_PORT=6379 \
  vendor/bin/phpunit -c phpunit.external.xml
```

`phpunit.external.xml` 强制使用相对路径 `.docker/.data/testing.sqlite`、合成加密密钥、Redis DB 14/15 和测试前缀。测试会重建数据库表，Redis 服务仍需隔离；仅使用 `CACHE_DRIVER=array` 不足以替代 Redis，因为测活锁等代码显式使用 Redis。环境如果配置了 `REDIS_URL`、`DATABASE_URL` 或缓存的 Laravel 配置，应先在测试环境中移除这些覆盖，不要把修正写回生产 `.env`。

覆盖解析与协议转换（含 AnyTLS 和 VLESS `packetEncoding=none`）、资格、鉴权、源编辑和选择、上游节点删除、空快照、失败保留、定时刷新、状态转换、延迟记录及失效清空、并发 slot、修订号竞争、SSRF 限制、凭据脱敏及主题生成。单元测试中的模拟探测成功不代表真实节点联网成功。

### 默认用户主题浏览器测试

脚本使用真实生成的默认主题 bundle 和合成 API 响应，阻断其他网络请求，无需真实订阅或管理员登录。Playwright 应在 Debian 工具目录安装，浏览器依赖准备好后运行；`PLAYWRIGHT_MODULE` 可指向工具目录中的 `playwright` 包，使用系统 Chromium 时另设 `CHROME_BINARY`。

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

脚本会检查自建节点显示不变、四种第三方状态颜色、在线延迟、无效或非在线旧延迟不展示、检测时间，以及悬停、键盘焦点、点击和 Esc 行为。提示文本须明确延迟是面板服务器经节点到检测站点的耗时。上游默认 bundle 校验失败时应审查兼容性，不应删除 SHA 校验后强行注入。

根 `AGENTS.md` 也给出了直接调用 `DefaultThemeHealthAsset::build(getcwd())` 的快捷命令。它会生成 `public/assets/external-subscriptions/xboard-dashboard-health-v2.js`，当前该文件不在 Git 忽略列表中；仅当确认是本次生成的测试产物时清理，不要把它加入提交。上述临时目录方法无需清理源码目录中的文件。

当前生成入口引用 `node-health-v2.js`，默认主题加载 `node-health-v2.css`。旧 v1 静态资源保留原内容，避免破坏已有强缓存页面；更新显示逻辑时应同步检查入口、脚本和样式版本，不覆盖旧版本 URL 的内容。只有经 SHA 校验的默认主题采用此入口，自定义主题保留原加载方式。

### 管理页浏览器测试

管理页脚本直接读取实际 Blade 模板、JavaScript 和 CSS，用合成管理 API 数据运行，无需 PHP 生成 bundle、真实订阅或管理员凭据。沿用上面的 Playwright / Chromium 配置：

```bash
node tests/browser/external-admin-latency.cjs
```

覆盖在线延迟和检测时间、离线/待复查/无有效结果/待检测、未勾选/停用/不支持状态，以及保存勾选后重新读取状态、保留搜索、30 秒缓存刷新、未保存时暂停刷新、移动端表格滚动和节点名称安全显示。刷新和勾选操作不会直接发起探测请求；延迟继续由每小时后台检测更新。

### 候选镜像与真实协议测试

在 Debian 本次任务目录构建候选镜像，默认目标为 AMD64；ARM64 构建与运行验证单独记录。不要在 Mac 启动 Colima。本项目 Docker 测试总内存上限为 2 GiB，错开重构建与运行测试，不能通过增加上限解决测试失败。

```bash
docker build -f Dockerfile.external -t xboard-external:candidate .
XBOARD_HEALTH_TEST_IMAGE=xboard-external:candidate \
  .github/scripts/run-health-smoke.sh
```

候选标签只是示例，应使用本任务独有且未被其他项目使用的标签。测试发布版本时将 `XBOARD_HEALTH_TEST_IMAGE` 替换为明确版本或 digest；测试当前改动不能拉取旧镜像后当作当前源码已验证。

脚本要求全新 Compose 项目，默认项目名为 `xboard-release-health`。如需指定新名字，设置以 `xboard-release-health` 开头的 `XBOARD_HEALTH_TEST_PROJECT`。已有该项目容器时脚本拒绝复用，不能删掉保护绕过。

测试使用 `internal` 网络、合成代理服务、一次性证书和仅容器内信任的 CA，不挂载生产数据，不访问真实上游节点。`11.254.233.2` 是仅限这个隔离测试网络的地址，不应复制到生产配置。容器硬限制为 1536 MiB，swap 上限同为 1536 MiB。脚本覆盖 VLESS、Trojan、Hysteria2、AnyTLS，验证正确凭据上线并记录延迟、错误凭据失败复查、恢复上线、目标异常、结果过期时不显示旧延迟及未选节点不检测等行为，并通过退出钩子删除 Compose 容器、网络和临时证书。

HTTP 与自动删除的完整启动步骤在 `.github/workflows/publish-external-image.yml` 内，复用时保持新安装实例、网络隔离和资源限制。三个 `smoke-*.php` 脚本均有破坏性合成数据写入，只能在脚本要求的新一次性实例内设置 `RELEASE_SMOKE_ALLOW_WRITE=1`，不能直接对业务站点执行。

测试完成检查退出码、容器状态、OOM 与内存，将必要日志和产物保存到 `/srv/buildlab/artifacts/xboard/<任务ID>/` 并验证可读，再清理本任务一次性资源。保留其他任务的数据、共享缓存和用户尚在查看的预览；不对共享 Docker 执行全局 prune，详见[清理要求](external-subscriptions.md#测试结束与清理)。

### 全局 DNS 测试

详见[全局 DNS 测试入口](node-dns.md#测试入口)。设置页覆盖鉴权、密钥不回显/不入存储、留空保留、等号值、重复键、冲突及失败保留输入；原后台测试覆盖新节点默认引用、旧节点保留独立配置、取消/切换模式和节点提交内容。

升级夹具使用同目录相对绑定持久化数据，从固定历史镜像建立合成旧站，升级到本次源码候选镜像，再重建检查数据仍在。旧节点、用户和第三方数据需保持不变；新表及全局配置在重建后保留。该脚本不验证真实 Cloudflare DNS 修改或 ACME 签发，也不声称已绑定全局的节点可直接降级旧镜像。

## 发布要求

1. 先核对当前任务是否明确要求推送或发布。完成代码审查及适用的 PHP、浏览器、协议测试；涉及迁移、运行时或启动流程时追加升级及回退边界验证。文档改动仅需校验文档，不为了交接更新 `latest`。
2. 检查工作树及待提交文件，确保 `.env`、真实订阅链接、数据库、客户端配置、证书和日志未进入提交或 Docker 构建上下文。
3. 将已核对的源码/提交同步到 Debian 后，仅在明确授权时从该任务 checkout 推送二开分支。Git 和镜像认证用安全输入及权限受限临时配置，完成后清理；不在 Mac 推送。
4. 镜像由 Debian 构建、验收及上传。历史 `Publish third-party subscription image` 由 `external-v*` 标签触发并在 Actions 上传，与最新 Debian 上传规范不同；发布机制尚未协调前，不推送此类触发标签。保留原流程的纯净性、全新安装、HTTP、自动删除、真实协议门禁，并加上 DNS 对应验收。先验证实际产物再更新 `latest`，不能仅凭标签存在判定成功。
5. 发布后核对 `latest` 与新版本标签的 digest、源码 revision 和 AMD64/ARM64 manifest，记录 Debian 发布日志及实际版本。不可移动历史标签。多架构构建不等于两种架构均运行测试；报告各平台实际验证范围。

工作流和 `Dockerfile.external` 固定基础运行时为 `ghcr.io/cedar2025/xboard@sha256:896e4926e0d7f18d223d58770590c74ab6f05ecb21f1b74ef5357ed20f44c676`，探测核心为官方 sing-box 1.14.3 的固定多架构 digest。`composer.json`、`composer.lock` 与基线 `4f48e61a2cbc6db5338872b6bdb45ef954ec1256` 必须一致；如果确实需要更改依赖，应单独更新运行时策略并验证，不删除工作流门禁。

仓库仍保留上游 `.github/workflows/docker-publish.yml`，它在 `master`、`new-dev` 推送或手动触发时使用普通 Dockerfile 发布到同一个镜像仓库，其中 `master` 会写 `latest`。二开历史使用 `publish-external-image.yml`；现行发布须满足 Debian 上传要求。未来合并到这些分支或调整发布方式前，先处理上传位置及标签冲突，避免跳过二开验收覆盖 `latest`；这次文档交接不修改工作流。

当前镜像通过专用 Dockerignore 允许列表打包应用代码，不复制本地 vendor。新增目录或运行时文件后检查 Dockerfile 和允许列表是否一起更新。默认主题健康状态资产由构建过程生成，不能只验证源码存在而忽略镜像是否带入。

## 存储、升级与回退边界

第三方数据在现有应用数据库中的 `external_subscriptions`、`external_subscription_nodes` 两表，测活字段也在节点表内。Redis 承担锁和缓存，不应作为订阅配置的唯一备份。原始 URL 和节点参数依赖原 `APP_KEY` 解密，数据库和 `.env` 必须配套备份和恢复。

`database/migrations/2026_11_05_000001_add_external_node_latency.php` 向节点表新增可空的无符号整数 `health_latency_ms`。新列不改写已有节点或勾选，升级前未记录延迟的在线节点继续显示在线，下一次成功探测后才显示数值。接口对非在线、已过期、未选或停用源的节点返回 `latency_ms: null`，不能把空值当成零延迟。旧版不会读取这一新增列；回退仍须保留该列，并验证旧版对新导入协议的实际支持范围。

生产升级保留原 Compose 挂载、数据库、Redis、主题、插件和 `APP_KEY`，只替换镜像并按[已有站点升级说明](external-subscriptions.md#已有站点使用发布镜像)操作。现有站点不运行 `xboard:install`，不执行数据库 rollback 命令。回退保留新增表和测活字段，使用升级前记录的固定镜像；新迁移、上游基础版本和插件兼容性需要针对实际部署重新验证，不能承诺任何历史镜像都安全回退。

全局 DNS 迁移 `2026_11_13_000001_create_global_dns_settings.php` 只新增表及空配置，不改旧节点；启动自动迁移，无需重装。环境变量依赖原 `APP_KEY`。旧镜像不能解析 `use_global_dns` 引用，启用后回退前应改回完整独立配置或恢复升级前一致备份，不能仅换旧镜像。具体操作和局限见 [DNS 升级与回退](node-dns.md#升级与回退)。

新交付 Compose 的数据库、配置、上传及其他持久化内容全部使用根 Compose 同目录的 `./` 相对绑定，整体搬迁保留属主与权限。历史 `compose.external-test.yaml` 有命名卷，不能作为当前新交付模板；DNS 升级夹具已采用相对绑定。

开发环境测试成功不表示已经修改生产站点。完成任务时分别说明代码推送、镜像发布、生产部署各自是否实际完成，以及本次测试、产物归档和清理结果。
