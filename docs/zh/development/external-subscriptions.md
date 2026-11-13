# 第三方订阅下发

本功能让 Xboard 从第三方订阅读取节点，由管理员勾选后，通过用户原有的 Xboard 订阅地址下发。无需为这些节点部署 Xboard 服务端。

开发接手先读仓库根目录的 [AGENTS.md](../../../AGENTS.md)，再读[开发、测试与发布交接](cloud-development.md)。本文侧重第三方订阅、管理操作和已有站点的升级方式。自建节点的 Cloudflare 等 DNS-01 参数复用见[全局 DNS 证书配置](node-dns.md)。

## 适用范围

- 用户必须存在、未被封禁、已关联套餐，且套餐未到期；永久有效套餐沿用 Xboard 的永久有效规则。
- 第三方节点的下发资格不检查 Xboard 的已用流量或剩余流量。自建节点继续使用原有资格规则。
- 只有同时满足「订阅源已启用、节点已勾选、上游仍存在、格式受支持」的节点才会下发。
- 下发节点保留上游名称，不追加第三方来源标记；遇到重名时仅追加 ` #2`、` #3` 等序号，以保证客户端配置中的名称唯一。
- 所有符合资格的用户收到同一组勾选节点，保留上游的 UUID、密码与连接参数。首版不按套餐、用户或用户组分别配置节点。
- 支持普通 URI 列表、Base64 编码的 URI 列表，以及 Clash YAML 的 `proxies` 节点列表。节点协议为 VLESS、Trojan、Hysteria2 和 AnyTLS；不支持或不能完整转换的参数会显示为不可勾选，不会悄悄删除关键参数后下发。
- 首版下发格式为通用订阅（v2rayN / Shadowrocket）、Mihomo / Clash Meta 和 Sing-box / Hiddify。旧版标准 Clash 不支持本次目标协议组合，请使用 Mihomo / Clash Meta。实际客户端也必须支持节点所用协议和参数；旧版本可能过滤不兼容节点。

AnyTLS 需要支持该协议的客户端内核：Sing-box 1.12+、Mihomo 1.19.3+ 或 Shadowrocket 2.2.65+（构建号 2592+）。检测到明确的旧内核版本时会过滤不兼容节点，不把客户端外壳的版本号当成内核版本。VLESS 的 `packetEncoding=none` 会保留禁用额外包编码的语义，不会被改成默认 XUDP。升级后重新同步此前标记不支持的节点，再勾选保存；不会自动选中新增协议节点。

本功能只处理节点配置下发，不代理用户流量，也不统计第三方节点的每用户用量。上游套餐的流量、到期和连接限制仍然生效。用户到期后，Xboard 会停止后续下发，但无法撤回已下载的节点凭据，也无法主动断开上游连接。

## 管理操作

1. 进入原 Xboard 后台，点击顶部的「第三方订阅管理」。也可以访问 `/{后台安全路径}/external-subscriptions`。
2. 页面自动读取同域原后台的有效管理员登录状态。没有登录时，输入原管理员邮箱和密码；普通用户无法读取或修改订阅源。
3. 点击「添加订阅源」，填写名称和完整第三方订阅地址。新建源默认停用，地址保存后不回显。
4. 点击「同步节点」。成功后，列表会显示节点名称、协议及是否可下发。
5. 勾选需要下发的节点，或者搜索后点击「勾选筛选结果」。该按钮只增加当前筛选结果中的可用节点；「清空勾选」会清空整个订阅源的勾选。
6. 点击「保存勾选」，再点击「启用下发」。用户更新原订阅时即可收到这些节点。

勾选变动在点击保存前不会生效。切换订阅源、刷新、同步或离开页面时，会提示尚未保存的更改。停用源会阻止下一次订阅更新继续下发该源；删除源还会删除其缓存节点和勾选记录。

管理页可见且空闲时，每 30 秒刷新一次本地快照，返回页面时也会刷新。正在操作、编辑订阅源或有未保存勾选时暂停，避免覆盖选择；自动刷新保留搜索和滚动位置，不会因此向上游发起同步请求。

编辑订阅源时，地址留空表示保留原地址。更换为另一个地址会自动停用源、清空原勾选并使旧节点失效，之后需要重新同步、勾选和启用。

## 同步规则

发布镜像内置独立同步 worker，启动后立即扫描已启用的订阅源，此后每 60 秒扫描一次。距离上次成功同步满 5 分钟的源才会重新拉取，因此通常约每 5–6 分钟更新一次；新启用且从未成功同步的源会在下一次扫描时拉取。自动尝试设置 1 分钟冷却，失败后保留旧快照，冷却结束后由后续扫描重试。

在 Compose 环境变量中设置 `ENABLE_EXTERNAL_SUBSCRIPTIONS_SYNC=false` 并重建容器，可关闭自动同步；管理页手动同步及不带 `--due` 的命令不受影响：

```bash
php artisan external-subscriptions:sync
```

非发布镜像部署可使用现有 Laravel scheduler：Kernel 已注册每 5 分钟执行 `external-subscriptions:sync --due`，需保证 scheduler 正常运行。`--due` 会检查启用状态、成功同步时间和冷却时间；独立 worker 与 scheduler 同时运行时也会通过锁及重新检查避免重复拉取。

- 新增和消失后重新出现的节点默认不勾选，需要管理员重新选择。
- 节点使用稳定指纹匹配，不依赖上游列表顺序。指纹由协议、地址、端口和名称确定；仅密码、UUID或顺序变化时保留原勾选，节点身份变化则需要重新检查和勾选。
- 每次成功同步都按完整快照处理：物理删除上游已消失的节点及其勾选记录，也清理旧版本遗留的失效记录；这些节点从管理列表和后续下发中消失。手动同步结果显示移除数量。
- HTTP 200 的空响应、Base64 解码后为空的内容，以及 `proxies: []` 都表示有效空快照，会清空该源的节点和勾选。
- 超时、非成功 HTTP 状态或整体格式无法解析的响应属于失败，不覆盖上次成功快照；管理页显示失败状态。不支持的单个节点仍会显示为不可勾选。
- 上游地址和节点配置加密存储，列表接口不返回原始地址或连接密钥，审计日志也不记录订阅 URL。

具备第三方下发资格的用户，即使启用源当前没有可下发节点，订阅接口仍返回 HTTP 200。Mihomo / Clash Meta 和 Sing-box 会收到阻断流量的空节点配置，避免退回直连；部分 URI 订阅客户端可能拒绝空订阅并保留旧配置。用户需要更新客户端订阅才能接收变更，服务端无法撤回客户端已经缓存的上游凭据。

## 用户面板的节点状态

已启用订阅源中已勾选、可下发的节点由服务器排队测活。新节点首次检测后，正常情况下每小时检查一次，全站最多同时探测两个节点。修改连接参数会使旧结果失效。打开面板、刷新订阅和内容相同的上游同步都不会触发额外探测。

测活使用节点本身的 VLESS、Trojan、Hysteria2 或 AnyTLS 配置，通过代理访问一个返回 HTTP 204 的 HTTPS 地址。成功代表从面板服务器出发，此节点在最近一次实测中能够代理请求。默认 Xboard 用户主题显示蓝点，并提供最近检测时间。第一次明确失败显示灰色「待复查」，五分钟后改用备用站，连续失败、面板服务器也能独立访问备用站时才显示红色「离线」。备用站本身不可达时显示灰色，并在下个小时再试。新节点、检测服务异常或超过两小时的旧结果显示灰色「未知」。自定义用户主题可读取节点接口的 `health_status`、`is_online`、`last_check_at` 和 `latency_ms` 展示相同状态。

管理页「延迟 / 检测」列和默认用户节点页显示 `123 ms` 一类的结果，并提供检测时间。数值来自现有每小时代理 HTTPS 204 请求，不额外测速或下载大文件；包括代理连接、TLS 和目标响应，不含预解析和内核启动。它反映面板服务器出发的请求耗时，与用户本机 ping 不同。成功耗时四舍五入到毫秒，低于 1 ms 显示 1 ms；未测、失败、待复查、过期、停用或取消勾选时不展示旧延迟。

离线只影响面板显示。下发仍以勾选、上游是否存在、格式及用户资格为准。已有站点升级由镜像启动流程迁移第三方节点表并添加检测字段；请保留原数据库和 `.env`。在 Compose 环境设置 `ENABLE_EXTERNAL_SUBSCRIPTIONS_HEALTH=false` 并重建容器可关闭定时测活。

## 镜像升级与 Debian Compose 测试

### 已有站点使用发布镜像

发布版本使用独立标签，历史 `ghcr.io/jinshenganyuci/xboard:external-v1.0.6` 支持 Linux AMD64 和 ARM64。`latest` 是可变标签，升级前核对本次发布的实际源码、digest 和验收结果，不能把历史版本当作包含新增全局 DNS 功能的镜像。首次发布若镜像为私有，需要在 GitHub Packages 设置中设为 Public 才能匿名拉取。

升级前停止容器并备份 `.env`、SQLite 数据目录（包括仍存在的 WAL/SHM）、主题、插件及 Redis 数据。已有站点只替换 Compose 的 `image`，保留所有原有数据挂载和 `APP_KEY`，然后执行：

```bash
docker compose pull xboard
docker compose up -d --force-recreate xboard
docker compose logs --tail=100 xboard
```

首次升级会新增第三方订阅的两张表，`external-v1.0.5` 另有四个测活字段及到期索引；v1.0.6 AnyTLS / 延迟版本追加可空的 `health_latency_ms` 字段。全局 DNS 功能只新增 `global_dns_settings` 表及空配置行，不更改旧节点。镜像启动流程执行迁移，无需重装或再次运行安装命令。打开原后台检查第三方订阅和全局 DNS 页面能加载；升级本身不会自动把旧节点改为全局 DNS。

回退保留新增表和字段，不执行 `migrate:rollback` 或 `xboard:rollback`。旧版可能缺少完整 AnyTLS 适配；旧镜像也不能解析全局 DNS 的 `use_global_dns` 引用。已经改绑全局的节点应先在新版本中恢复完整独立参数，或使用升级前的一致备份，再回退固定镜像。不能承诺只换回任意旧镜像就能正常运行。详见 [DNS 升级与回退](node-dns.md#升级与回退)。

镜像版本通过 OCI 标签和镜像 digest 标识，应用内原上游版本信息可能仍显示基础版本。此发布方式统一通过 Compose 换镜像升级，不使用后台源码更新按钮或 `update.sh`。

`Dockerfile.external` 在固定官方运行时上覆盖功能代码，不重新解析或升级 Composer 依赖。专用 Dockerignore 只允许打包需要的源码目录；`.env`、数据库、日志和测试凭据不进入构建上下文。若修改 Composer 依赖，需要先更新运行时和兼容性验证。历史 `external-v*` 标签会触发 Actions 上传；当前用户要求 GitHub 推送和镜像上传统一由 Debian 完成，未协调发布机制前不要推送这类触发标签，也不要因测试通过自动发布。

### 开发与测试环境

Mac 负责源码编辑、轻量语法检查和查看结果；Docker 构建、完整测试及预览服务在 Debian 编译虚拟机的本任务目录中运行。不启动 Mac Colima，不在虚拟化宿主机编译。按用户全局规范核验机器和任务归属，复用依赖缓存，记录代码版本、日志及资源。

历史 `compose.external-test.yaml` 绑定源码、使用官方镜像，缺少发布镜像中的独立探测配置，并包含命名卷；不能用于证明当前发布镜像已通过，也不能作为新的 Compose 交付模板。新交付 Compose 的所有持久化目录都应绑定到根 Compose 同目录的 `./` 路径，例如 `./data`、`./redis` 和 `./storage`，不得使用命名卷、匿名卷或目录外路径。迁移现有数据时保留原内容、属主和权限。

本项目 Docker 测试总内存不得超过 2 GiB。DNS 升级夹具的单容器 `mem_limit` / `memswap_limit` 均为 `1536m`；构建、安装和运行测试错开，不同时保留多套运行实例。检查内存和 OOM，不把资源上限当成实际用量。

### 测试入口

第三方功能的 PHPUnit、默认用户主题浏览器、管理页浏览器和真实四协议 Compose 测试命令见[开发交接](cloud-development.md#测试入口与覆盖范围)。完整测试使用独立 `.docker/.data/testing.sqlite`、合成 `APP_KEY`、Redis DB 14/15 和测试前缀；测试会重建表，禁止连接生产数据或业务 Redis。

全局 DNS 相关测试见 [DNS 测试入口](node-dns.md#测试入口)：

- `tests/Unit/NodeDns/`、`tests/Feature/NodeDns/` 已纳入 `phpunit.external.xml`。
- `tests/browser/node-dns-settings.cjs` 使用实际设置页资产与合成接口。
- `tests/browser/node-dns-form.cjs` 使用实际生成的原后台 React bundle。
- `.github/scripts/run-node-dns-upgrade.sh` 从固定旧镜像建立一次性合成旧站，使用同一挂载升级为候选镜像并再次重建，核对自动迁移和持久化；不是生产降级工具。

浏览器与配置下发测试不验证真实 Cloudflare Token 或 ACME 签发。测试通过范围以本任务实际日志为准，不能套用历史测试数或镜像结果。

为用户交付预览时，服务由 Debian 持续托管，提供完整、已从 Mac 访问验证的局域网地址。无需向用户交付的隔离测试无需暴露业务端口。用户仍在查看预览时保留服务，确认结束后再清理。

### 测试结束与清理

先保存必要日志、报告和截图到 Debian 本任务永久产物目录，验证可读并更新 `task.json`。然后只删除本次一次性容器、网络、临时证书、测试数据及明确不再需要的候选镜像；原有服务、用户数据、其他任务资源和共享缓存保留。不要执行无范围的 `docker system prune`。

`run-health-smoke.sh` 会清理自己的 Compose 项目及临时证书；DNS 升级脚本清理容器和网络，保留任务目录中的数据和日志等待归档。失败或中断后仍须复核资源，不把退出钩子启动等同于清理完成。当前流程不使用 Colima；文档里的 v1.0.6 Colima 记录仅说明历史结果。

## 代码入口

| 部分 | 位置 |
| --- | --- |
| 管理 API | `app/Http/Controllers/V2/Admin/ExternalSubscriptionController.php` |
| 管理 API 路由 | `app/Http/Routes/V2/AdminRoute.php` |
| 管理页面 | `resources/views/admin/external-subscriptions.blade.php` |
| 页面脚本和样式 | `public/assets/external-subscriptions/` |
| 订阅源与节点模型 | `app/Models/ExternalSubscription.php`、`ExternalSubscriptionNode.php` |
| 获取、解析与同步 | `app/Services/ExternalSubscription/` |
| 定时同步命令 | `app/Console/Commands/SyncExternalSubscriptions.php` |
| 测活状态与调度 | `app/Services/ExternalSubscription/HealthService.php`、`app/Console/Commands/ProbeExternalSubscriptionHealth.php` |
| 真实代理探测 | `app/Services/ExternalSubscription/ProbeTransport.php`、`app/Protocols/SingBox.php` |
| 发布镜像独立 worker | `.docker/external-subscriptions/worker.sh`、`.docker/external-subscriptions/supervisor.conf` |
| 默认主题健康状态入口 | `app/Support/DefaultThemeHealthAsset.php`、`theme/Xboard/dashboard.blade.php` |

管理接口位于 `/api/v2/{后台安全路径}/external-subscription/`：GET `fetch`、`nodes`；POST `save`、`drop`、`sync`、`select`。所有接口沿用管理员 Sanctum Bearer 鉴权。`select` 接收源 `id` 和完整 `node_ids` 数组，空数组表示全部取消；跨源、失效及不支持的节点会被拒绝。

独立管理页只输出空壳，敏感数据由鉴权接口加载。页面复用原后台登录态，不修改上游已编译的管理端 JavaScript。

## 备份与迁移

订阅地址和节点配置由 `APP_KEY` 加密。备份必须同时包含 `.env` 中的原始 `APP_KEY` 和数据库；只迁移数据库或重新生成 `APP_KEY` 会导致已有订阅配置无法解密。SQLite 使用 WAL 时，先停容器再复制数据库及相关 WAL/SHM 文件，或使用 SQLite 的一致性备份方式。

不要提交 `.env`、真实订阅地址、测试账号文件、SQLite 数据库或客户端连接配置。原始订阅地址只在管理界面输入。迁移到正式部署时使用正常网络环境和正式 HTTPS 地址，不需要本机测试时临时添加的 DNS 或私密测试覆盖文件。
