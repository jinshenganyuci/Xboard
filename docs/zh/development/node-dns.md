# 全局 DNS 证书配置

这项扩展让管理员保存一组 DNS 提供商参数，供多个自建节点申请 DNS-01 证书时复用。后续添加节点只需确认各节点的证书域名，Cloudflare 等提供商的凭据不用重复输入。它不修改第三方订阅，也不代替节点端执行 ACME 签发。

本文描述 2026-11-13 加入的源码行为和验证入口，不代表新镜像已发布或本次测试已经通过。`external-v1.0.6` 是此前 AnyTLS / 延迟功能的历史发布版本；具体交付以当前任务的测试结果、源码提交和镜像 digest 为准。

## 管理员如何使用

1. 从原后台顶部进入「全局 DNS 证书配置」，或访问 `/{后台安全路径}/dns-settings`。页面复用同域原后台管理员登录；未登录时使用原管理员账号，普通用户无权限。
2. 填写提供商（Cloudflare 为 `cloudflare`）、通知邮箱和环境变量。环境变量每行一项 `KEY=VALUE`，例如 `CF_API_TOKEN=在此填写自己的令牌`。只在实际页面中输入真实令牌，不将其放入源码、测试或工单日志。
3. 保存后页面显示「密钥已保存 · 不回显」，输入框清空。之后仅修改邮箱时，环境变量留空会保留原值；输入新值会替换整组环境变量。首次配置或换提供商必须填写对应凭据。
4. 添加节点时进入「高级设置 → TLS」，选择 `dns-01 (ACME)`。全局已配置且新节点尚无独立参数时，默认开启「使用全局 DNS 配置」；也可手动切换。
5. 确认该节点的证书域名，保存高级设置，再提交节点。域名不是全局固定值：可从节点 TLS 域名或节点地址建议填写，但 IP 地址和 URL 不作为证书域名。用户应核对建议值和 DNS 权限范围。

旧节点升级后仍沿用原独立配置，不会自动改绑全局。编辑旧节点可主动开启全局；关闭开关则使用该节点自己的参数。尚未提交的独立参数在当前编辑会话内可恢复，取消高级设置也会还原修改；已保存为全局引用的节点不会保留一份全局密钥供取消引用时自动填回。

全局更新会影响所有引用它的节点。独立节点不受影响，证书域名保持各自配置。节点配置变化通知会发送给已连接节点；未连接节点在之后拉取配置时得到新参数。是否立即重签、何时续期以及签发失败的具体处理由节点端决定。

设置页会标记未保存修改。网络失败、校验失败和版本冲突保留当前输入；其他管理员已修改配置时返回 409，点击「保留输入并读取最新配置」，核对后再次保存。页面不会自动重试覆盖他人的更新。

## 数据与接口

新增表 `global_dns_settings` 保存一行全局设置：`dns_provider`、`email`、`dns_env`、`revision` 和时间戳。`dns_env` 通过 Laravel 的 `encrypted:array` 使用原 `APP_KEY` 加密；提供商、邮箱和版本号属于可读元数据。全局引用节点的 `cert_config` 仅保存：

```json
{
  "cert_mode": "dns",
  "domain": "node.example.com",
  "use_global_dns": true
}
```

只有已鉴权的节点配置下发会解析出提供商、邮箱和环境变量；HTTP 配置与 WebSocket 更新共用该解析逻辑。普通用户节点列表和订阅不能得到 DNS 凭据。管理查询只返回元数据，密钥也不写入浏览器本地存储或会话存储；原后台登录令牌仍沿用已有登录存储方式。

| 接口 | 请求与行为 |
| --- | --- |
| `GET /api/v2/{后台安全路径}/dns-settings/fetch` | 管理员鉴权，返回脱敏元数据，`Cache-Control: no-store` |
| `POST /api/v2/{后台安全路径}/dns-settings/save` | 管理员鉴权，提交 `revision`、`dns_provider`、`email` 及可选 `dns_env`；成功返回新元数据 |

两者响应形状为：

```json
{
  "data": {
    "configured": true,
    "dns_provider": "cloudflare",
    "email": "certs@example.com",
    "has_dns_env": true,
    "updated_at": "2026-11-13T03:04:05+00:00",
    "revision": 1
  }
}
```

未配置时 `configured` / `has_dns_env` 为 `false`，`revision` 为 `0`，`updated_at` 为 `null`。POST 必须提交最后读取的整数版本号；版本不匹配返回 409。省略 `dns_env`、传 `null` 或空数组表示保留；不能用空值清除配置，本功能没有删除或重置全局配置入口。

提供商只接受小写字母开头的 1–64 位字母、数字、下划线或连字符；邮箱必填，最长 254 字符。环境变量最多 64 项，键名最多 64 字符且不能数字开头；每个值为非空单行文本，最多 4096 UTF-8 字节，整个 JSON 不超过 16 KiB。textarea 只按每行的第一个 `=` 拆分，因此值中可包含 `=`；重复键拒绝提交。错误提示和审计记录不得带回环境变量原文。

## 升级与回退

已有站点保留 `.env`、原 `APP_KEY`、数据库及所有原有数据挂载，替换为已经验证且明确发布的候选版本镜像后重建容器。镜像启动时自动执行新增迁移，无需重装或再次运行 `xboard:install`。本次迁移只创建 `global_dns_settings` 及一条不含凭据的初始记录，不改旧节点、用户、订单和第三方订阅数据。

新部署或交付 Compose 的持久化目录必须使用根 Compose 同目录的 `./` 相对绑定。数据库、配置、Redis、主题、上传和应用生成内容一起备份，保留权限与属主。迁移整个目录时一并移动数据；生产升级不能为了改挂载形式而丢弃旧数据。

降级有明确边界：**旧镜像不认识 `use_global_dns`，无法替已绑定全局的节点补齐凭据。** 回退前应在新版本中将这些节点改回完整独立配置，或恢复升级前一致的数据库和配置备份。仅新增表而尚未改绑节点时，旧版可以忽略新表，但仍需按实际旧镜像、插件和数据状态验证。不要执行 `migrate:rollback` / `xboard:rollback`；不能承诺所有旧版本都可无条件换镜像回退。

## 测试入口

源码在 Mac 编辑和轻量检查。下面的 PHPUnit、浏览器、镜像构建及 Docker Compose 验证全部在 Debian 编译虚拟机的独立任务 checkout 执行。用合成配置、独立 SQLite 和 Redis，不使用生产数据库、真实 DNS 凭据或截图中的 Token。

基础检查纳入 `phpunit.external.xml`；可先运行 DNS 范围，再按关联接口的改动运行完整回归：

```bash
vendor/bin/phpunit -c phpunit.external.xml tests/Unit/NodeDns
vendor/bin/phpunit -c phpunit.external.xml tests/Feature/NodeDns
vendor/bin/phpunit -c phpunit.external.xml
```

测试覆盖管理员鉴权、环境变量加密/脱敏、更新保留和冲突、旧节点不变、引用保存与复制、HTTP/WebSocket 下发、更新 ETag、用户订阅不泄漏及异常时拒绝下发。测试环境准备与 Redis 约束见[开发交接](cloud-development.md#php-测试)。

设置页浏览器回归直接使用实际 Blade/JS/CSS 和合成响应；原后台节点表单回归需要经 SHA 校验的实际管理 bundle。以下 Bash 示例将生成资产放到本任务临时目录，不覆盖仓库原有文件：

```bash
node tests/browser/node-dns-settings.cjs
(
  set -eu
  XBOARD_DNS_BROWSER_TMP=$(mktemp -d)
  trap 'rm -rf "$XBOARD_DNS_BROWSER_TMP"' EXIT
  php -r 'require "app/Support/AdminDnsAsset.php"; file_put_contents($argv[1], App\Support\AdminDnsAsset::compile(file_get_contents("public" . App\Support\AdminDnsAsset::SOURCE)));' \
    "$XBOARD_DNS_BROWSER_TMP/admin-dns.js"
  XBOARD_ADMIN_DNS_BUNDLE="$XBOARD_DNS_BROWSER_TMP/admin-dns.js" \
    node tests/browser/node-dns-form.cjs
)
```

可用 `PLAYWRIGHT_MODULE` 指定 Playwright 包，`CHROME_BINARY` 指定 Chromium。设置页截图使用 `XBOARD_DNS_SETTINGS_SCREENSHOT`；浏览器 mock 不访问真实 Cloudflare。原后台源 bundle 校验失败时需重新审查适配，不得去掉 SHA 或替换次数检查强行注入；`admin-dns-v1.js` 是构建产物，不提交。

Docker 升级夹具从固定的历史镜像创建合成旧站，再用当前候选镜像原挂载升级并重建，检查持久化。所选候选镜像必须已在 Debian 用本次源码构建，不能拿旧 `latest` 的结果代表新代码。示例中的目录须事先核对归属并为空：

```bash
XBOARD_DNS_CANDIDATE_IMAGE=xboard-external:dns-candidate \
XBOARD_DNS_TEST_ROOT=/srv/buildlab/tasks/xboard/本任务ID/dns-upgrade \
  .github/scripts/run-node-dns-upgrade.sh
```

`XBOARD_DNS_OLD_IMAGE` 可指定已核实的旧镜像；默认使用交接文档记录的 v1.0.6 digest。`XBOARD_DNS_TEST_PROJECT` 必须以 `xboard-dns-upgrade-` 开头。Compose 夹具复制到任务目录后，所有持久化挂载均为同目录 `./` 路径，容器内存和含 swap 上限为 1536 MiB；本项目 Docker 测试总内存仍不得超过 2 GiB。

脚本仅适用于一次性空测试目录。退出时清理本项目容器和网络，保留目录中的测试数据和 `state/` 日志待归档；确认产物可读后，再删除本任务不需要的残留。该入口验证升级与再次启动持久化，不等同于全局引用节点能在旧镜像正常工作，也不是生产回退方案。

## 当前限制

- 只有一组全局凭据；不同 DNS 账号的节点仍使用独立参数，没有多套模板或按域名自动选账号。
- 只有 DNS-01 使用全局设置，HTTP-01、自签、内容推送等保留原行为。
- 提供商及环境变量名称最终由节点端 DNS/ACME 实现支持；面板校验格式不验证令牌的有效性或具体域名权限。
- 合成 API、节点配置和升级测试不涉及真实 Cloudflare 修改 DNS，也不证明真实 ACME 证书签发/续期成功；这项实网验证未在本功能中宣称完成。
- 原后台适配锁定经校验的官方管理资源；换后台版本或自定义管理主题需要重新适配与测试。
- 代码完成或测试通过不等于已推送、已发布或已部署。GitHub 推送和镜像上传仅在本次任务明确授权后，从 Debian 执行。
