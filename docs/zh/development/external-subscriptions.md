# 第三方订阅下发

本功能让 Xboard 从第三方订阅读取节点，由管理员勾选后，通过用户原有的 Xboard 订阅地址下发。无需为这些节点部署 Xboard 服务端。

## 适用范围

- 用户必须存在、未被封禁、已关联套餐，且套餐未到期；永久有效套餐沿用 Xboard 的永久有效规则。
- 第三方节点的下发资格不检查 Xboard 的已用流量或剩余流量。自建节点继续使用原有资格规则。
- 只有同时满足「订阅源已启用、节点已勾选、上游仍存在、格式受支持」的节点才会下发。
- 下发节点保留上游名称，不追加第三方来源标记；遇到重名时仅追加 ` #2`、` #3` 等序号，以保证客户端配置中的名称唯一。
- 所有符合资格的用户收到同一组勾选节点，保留上游的 UUID、密码与连接参数。首版不按套餐、用户或用户组分别配置节点。
- 支持普通 URI 列表、Base64 编码的 URI 列表，以及 Clash YAML 的 `proxies` 节点列表。首版节点协议为 VLESS、Trojan 和 Hysteria2；不支持或不能完整转换的参数会显示为不可勾选，不会悄悄删除关键参数后下发。
- 首版下发格式为通用订阅（v2rayN / Shadowrocket）、Mihomo / Clash Meta 和 Sing-box / Hiddify。旧版标准 Clash 不支持本次目标协议组合，请使用 Mihomo / Clash Meta。实际客户端也必须支持节点所用协议和参数；旧版本可能过滤不兼容节点。

本功能只处理节点配置下发，不代理用户流量，也不统计第三方节点的每用户用量。上游套餐的流量、到期和连接限制仍然生效。用户到期后，Xboard 会停止后续下发，但无法撤回已下载的节点凭据，也无法主动断开上游连接。

## 管理操作

1. 进入原 Xboard 后台，点击顶部的「第三方订阅管理」。也可以访问 `/{后台安全路径}/external-subscriptions`。
2. 页面自动读取同域原后台的有效管理员登录状态。没有登录时，输入原管理员邮箱和密码；普通用户无法读取或修改订阅源。
3. 点击「添加订阅源」，填写名称和完整第三方订阅地址。新建源默认停用，地址保存后不回显。
4. 点击「同步节点」。成功后，列表会显示节点名称、协议及是否可下发。
5. 勾选需要下发的节点，或者搜索后点击「勾选筛选结果」。该按钮只增加当前筛选结果中的可用节点；「清空勾选」会清空整个订阅源的勾选。
6. 点击「保存勾选」，再点击「启用下发」。用户更新原订阅时即可收到这些节点。

勾选变动在点击保存前不会生效。切换订阅源、刷新、同步或离开页面时，会提示尚未保存的更改。停用源会阻止下一次订阅更新继续下发该源；删除源还会删除其缓存节点和勾选记录。

编辑订阅源时，地址留空表示保留原地址。更换为另一个地址会自动停用源、清空原勾选并使旧节点失效，之后需要重新同步、勾选和启用。

## 同步规则

已启用的订阅源每 30 分钟自动同步一次，也可在管理页手动同步。后台命令为：

```bash
php artisan external-subscriptions:sync
```

自动同步需要 Laravel scheduler 正常运行。提供的测试 Compose 已包含 `schedule:work`；接入其他部署时，请保留原有 scheduler 服务。

- 新节点默认不勾选，避免上游新增节点直接暴露给用户。
- 节点使用稳定指纹匹配，不依赖上游列表顺序。指纹由协议、地址、端口和名称确定；仅密码、UUID或顺序变化时保留原勾选，节点身份变化则需要重新检查和勾选。
- 上游已移除的节点会标记失效，不再下发。
- 超时、HTTP 错误或无法解析的响应不会覆盖上次成功快照；管理页显示失败状态。
- 上游地址和节点配置加密存储，列表接口不返回原始地址或连接密钥，审计日志也不记录订阅 URL。

## 本地 Docker Compose 测试

### 已有站点使用发布镜像

发布版本使用独立标签，例如 `ghcr.io/jinshenganyuci/xboard:external-v1.0.3`，支持 Linux AMD64 和 ARM64。发布任务通过启动及订阅检查后会将 `ghcr.io/jinshenganyuci/xboard:latest` 更新为同一镜像。先确认 GitHub Actions 的对应发布任务成功；首次发布若镜像为私有，需要在 GitHub Packages 设置中将镜像设为 Public，才能匿名拉取。

升级前停止容器并备份 `.env`、SQLite 数据目录（包括仍存在的 WAL/SHM）、主题、插件及 Redis 数据卷。已有站点只替换 Compose 的 `image`，保留所有原有挂载和 `APP_KEY`，然后执行：

```bash
docker compose pull xboard
docker compose up -d --force-recreate xboard
docker compose logs --tail=100 xboard
```

启动会自动新增第三方订阅的两张表，不要再次运行安装命令。打开原后台并检查第三方订阅页面能加载。回退只换回升级前固定的官方镜像并重建容器，保留数据库中的新增表；不要执行 `migrate:rollback` 或 `xboard:rollback`。

镜像版本通过 OCI 标签和镜像 digest 标识，应用内原上游版本信息可能仍显示基础版本。此发布方式统一通过 Compose 换镜像升级，不使用后台源码更新按钮或 `update.sh`。

发布流程由 `external-v*` Git 标签触发，使用 `Dockerfile.external` 在已验证的官方运行时上覆盖功能代码，不重新解析或升级 Composer 依赖。专用 Dockerignore 只允许打包需要的源码目录；本地 `.env`、数据库、日志和订阅测试凭据不进入构建上下文。若后续修改 Composer 依赖，需要先更新运行时和兼容性验证，发布工作流会拒绝直接复用旧依赖。

### 开发测试实例

`compose.external-test.yaml` 用于独立本地测试。它绑定当前源码，采用 SQLite，所有服务合在一个容器内运行，并仅发布到 `127.0.0.1:17001`。

内存限制分两层：

| 范围 | 配置 |
| --- | --- |
| Docker 虚拟机 | 总内存设为 2 GiB |
| Xboard 测试容器 | `mem_limit: 1536m`、`memswap_limit: 1536m` |

Docker Desktop 用户在资源设置中将内存设为 2 GiB。使用 Colima 时可创建专用测试实例，例如 `colima start --profile xboard-test --memory 2 --cpu 2`，并让后续 Docker 命令使用对应 context。不要同时运行其他测试容器；安装依赖和初始化用的一次性容器也应在正式测试容器启动前运行。不要为了运行此测试覆盖现有生产环境的 `.env` 或数据库。

### 全新测试实例

在一个全新的 checkout 中执行：

```bash
git submodule update --init --recursive
mkdir -p .docker/.data
touch .env

docker compose -f compose.external-test.yaml pull

docker compose -f compose.external-test.yaml run --rm --no-deps \
  -e CACHE_DRIVER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e COMPOSER_MEMORY_LIMIT=768M --entrypoint sh xboard -lc \
  'composer install --no-dev --prefer-dist --no-interaction --no-scripts && php artisan package:discover --ansi'

docker compose -f compose.external-test.yaml run --rm --no-deps \
  -e ENABLE_SQLITE=true -e ENABLE_REDIS=true \
  -e ADMIN_ACCOUNT=admin@example.com xboard php artisan xboard:install

docker compose -f compose.external-test.yaml up -d
```

安装命令生成管理员密码和后台安全路径，请自行保存。`admin@example.com` 是示例，安装时替换成自己的管理员邮箱。安装生成的 `.env` 中 SQLite 路径应是项目相对路径 `.docker/.data/database.sqlite`，内置 Redis 使用 `/data/redis.sock`。

访问 `http://127.0.0.1:17001/{安装输出的后台安全路径}`。测试页入口就在后台顶部。修改宿主机监听端口时，可设置 `XBOARD_TEST_PORT`；默认不对局域网或公网开放。

检查运行与内存：

```bash
docker compose -f compose.external-test.yaml ps
docker compose -f compose.external-test.yaml logs --tail=80 xboard
docker stats --no-stream
```

测试容器按 1536 MiB 硬上限运行，不等于始终占用这些内存；Docker VM 的 2 GiB 上限负责约束整个虚拟机。若资源不足，应减少其他负载，而不是增加这里的上限。

### 更新已有测试实例

先备份 `.env` 和 SQLite 数据库，再更新源码及子模块。不要重复执行初始化安装命令。

```bash
docker compose -f compose.external-test.yaml stop

docker compose -f compose.external-test.yaml run --rm --no-deps \
  -e CACHE_DRIVER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  xboard php artisan migrate --force

docker compose -f compose.external-test.yaml up -d
```

Octane 是常驻进程。修改 PHP 代码后需要重启测试容器或重新加载 Octane，避免仍执行旧代码。

## 自动化测试

测试依赖 PHPUnit 等 Composer 开发依赖。正式使用无需保留这些依赖；验收时在一次性测试环境中安装，先停止常驻测试容器，保持总内存限制。

```bash
docker compose -f compose.external-test.yaml stop

docker compose -f compose.external-test.yaml run --rm --no-deps \
  -e CACHE_DRIVER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e COMPOSER_MEMORY_LIMIT=768M --entrypoint sh xboard -lc \
  'composer install --prefer-dist --no-interaction --no-scripts && php artisan package:discover --ansi'

docker compose -f compose.external-test.yaml up -d

docker compose -f compose.external-test.yaml exec xboard sh -lc \
  'mkdir -p .docker/.data && touch .docker/.data/testing.sqlite && vendor/bin/phpunit -c phpunit.external.xml'
```

测试通过 `exec` 在同一个 1536 MiB 容器中执行，复用其内置 Redis，不另开运行中的测试容器。部分现有代码显式使用 Redis，因此不能在没有 Redis 的单独 Shell 容器里运行完整测试。

`phpunit.external.xml` 强制使用独立的 `.docker/.data/testing.sqlite`、测试用加密密钥，以及隔离的 Redis DB 14/15 和测试 key 前缀。不要改成业务数据库路径或业务 Redis DB；测试会重建表。测试进程的环境覆盖不应写回 `.env`，常驻服务保持 `APP_ENV=production`。验收覆盖解析、同步保留、用户资格、凭据保留、管理员权限、勾选边界及响应/审计脱敏。真实节点是否能联网还需要单独进行客户端连接测试，不能由单元测试代替。

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

管理接口位于 `/api/v2/{后台安全路径}/external-subscription/`：GET `fetch`、`nodes`；POST `save`、`drop`、`sync`、`select`。所有接口沿用管理员 Sanctum Bearer 鉴权。`select` 接收源 `id` 和完整 `node_ids` 数组，空数组表示全部取消；跨源、失效及不支持的节点会被拒绝。

独立管理页只输出空壳，敏感数据由鉴权接口加载。页面复用原后台登录态，不修改上游已编译的管理端 JavaScript。

## 备份与迁移

订阅地址和节点配置由 `APP_KEY` 加密。备份必须同时包含 `.env` 中的原始 `APP_KEY` 和数据库；只迁移数据库或重新生成 `APP_KEY` 会导致已有订阅配置无法解密。SQLite 使用 WAL 时，先停容器再复制数据库及相关 WAL/SHM 文件，或使用 SQLite 的一致性备份方式。

不要提交 `.env`、真实订阅地址、测试账号文件、SQLite 数据库或客户端连接配置。原始订阅地址只在管理界面输入。迁移到正式部署时使用正常网络环境和正式 HTTPS 地址，不需要本机测试时临时添加的 DNS 或私密测试覆盖文件。
