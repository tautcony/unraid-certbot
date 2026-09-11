# 本地调试

用于在开发机（macOS / Linux）预览插件界面并运行完整的续期流程。该环境不会读写宿主机的 `/boot`、`/usr/local/emhttp`，也不会真正调用 Docker 或 Let's Encrypt。

## 快速开始

```bash
./dev.sh                # 初始化沙箱并启动预览服务器
```

启动后访问 <http://127.0.0.1:8080>：

| 地址 | 内容 |
|---|---|
| `/` | 调试首页，显示沙箱状态和快捷入口 |
| `/Settings/UnraidCertbot` | 设置页，对应真机的 设置 → 网络服务 → Unraid Certbot（默认停在证书状态标签） |
| `/Settings/UnraidCertbot?tab=config` | 同一页的设置标签（另有 `?tab=history`、`?tab=log`） |
| `/Utilities/CertStatus` | 兼容地址，302 跳到上面的证书状态标签（该页已不在任何菜单里） |

设置页是一整页，没有二级入口也没有弹窗：「证书状态」标签显示状态与运行环境，「设置」标签是设置表单，另外两个是续期历史与运行日志。标签切换把 `tab=` 写进地址栏，刷新和收藏都能回到同一标签。

「立即检查并续期」「强制续期」会实际执行 `scripts/renew.sh`，其中 `docker` 替换为不联网的假实现。

预览服务器的路由顺序：先匹配设置页，再匹配插件目录里以 `.php` 结尾的端点（`exec.php` 等，按真机那样由 PHP 执行，而不是当静态文件发出去），最后才把 `dev/run` 里真实存在的文件（`sheets/*.css`、`default.cfg`、`logging.htm` 等）交给内置服务器。`.page` 正文如果发了 `Location`（CertStatus.page 就是这种），预览按真机的响应处理，不再套预览外壳。

页面样式表按真机规则加载：渲染 `<页面名>.page` 时自动引入同插件的 `sheets/<页面名>.css`。

预览界面的外壳与控件样式取自 Unraid 7 webGUI 源码（见下文「界面还原度」），外观与真机基本一致。

常用命令：

```bash
./dev.sh status          # renew.sh --status，查看沙箱中解析出的配置
./dev.sh renew           # 执行一次续期（证书未到期时进入「未变化」分支）
./dev.sh renew --force   # 强制续期，重新签发并覆盖 bundle
./dev.sh reset           # 删除 dev/run 并重建（配置与证书一并重置）
./dev.sh doctor          # 检查本机依赖
./dev.sh --port 9000     # 指定其它端口
./dev/seed.sh --quiet    # 仅初始化沙箱，不打印进度
```

## 界面还原度

外壳（顶栏、菜单、`div.title`、页脚）与控件（`dl` 两列表单、表格、输入框、下拉框、按钮、`blockquote`、标签页）的样式直接取自 Unraid 7 webGUI 源码：

| Unraid 文件 | 用到的部分 |
|---|---|
| `dynamix/styles/default-color-palette.css` | `--gray-*`、`--orange-*`、`--brand-*` 等调色板 |
| `dynamix/styles/themes/black.css` | 默认主题：深色正文 + 浅色顶栏 |
| `dynamix/styles/themes/white.css` | 白色主题：浅色正文 + 深色顶栏 |
| `dynamix/styles/default-base.css` | `#header` / `#menu` / `.nav-item` / `div.title` / `dl,dt,dd` / 表格 / 表单 / 页脚 |
| `dynamix/include/DefaultPageLayout/Header.php` | UNRAID wordmark 的 SVG 路径与红→橙渐变 |
| `dynamix/include/DefaultPageLayout/{MainContent,Footer}.php` | 内容与页脚结构 |
| `dynamix/include/MarkdownExtra.php` | 定义列表渲染成 `<dl><dt>…</dt><dd>…</dd>` 的结构（本地用等价实现） |

与真机一致的细节：

- 默认为 Unraid 的 **white** 主题（深色顶栏 `#1d1b1b` + 浅色正文 `#f2f2f2`），与真机中文界面截图一致。页脚的「主题」按钮可切换至 black，选择保存在浏览器 localStorage，也可用 `?theme=black` 一次性指定。
- 顶部菜单使用真机中文语言包的名称（仪表板 / 主界面 / 共享 / 用户 / 设置 / 插件 / DOCKER / 虚拟机 / 应用 / 工具），当前页带下划线；仅预览中真实存在的页面可点击。预览没有仿真「设置」里的分类页，所以看不到「网络服务 → Unraid Certbot」这一层，入口页按 `/Settings/UnraidCertbot` 直达即可。
- 说明文字默认隐藏，这是 Unraid 7 的行为（`.inline_help { display: none }`）。点击表单标签可展开该条说明，点击顶部「帮助」可一次展开或收起全部，`?help=1` 则全部展开。
- 「应用」按钮默认禁用，表单有改动时才启用；设置页自带一份启用逻辑，不依赖外壳的 jQuery，因为标签内容是页面里的独立区块。
- 表格为浅色斑马纹与大写灰色表头，输入框为下划线样式，标题条使用 `--title-header-background-color`（gray-150）。

## 沙箱

`dev/run/` 为运行时目录（已加入 `.gitignore`），结构与 Unraid 保持一致：

```
dev/run/
  usr/local/emhttp/                               $docroot
    logging.htm                                   -> dev/emhttp/logging.htm
    plugins/dynamix/include/Wrappers.php          -> dev/emhttp/.../Wrappers.php
    plugins/unraid-certbot/                       -> source/.../unraid-certbot（软链，改源码立即生效）
  boot/config/plugins/unraid-certbot/             配置 / 凭据 / 历史 / 日志 / cron
  boot/config/ssl/certs/<主机名>_unraid_bundle.pem
  boot/config/letsencrypt/live/<主域名>/          certbot 的证书目录
  var/local/emhttp/var.ini                        Unraid 服务器名（决定 bundle 文件名）
  bin/docker                                      -> dev/bin/docker（假 docker）
  etc/rc.d/rc.nginx                               -> dev/bin/rc.nginx（假重启）
```

样例数据默认为主机名 `tower`、域名 `example.com,www.example.com`，可通过 `CB_DEV_HOST` / `CB_DEV_EMAIL` / `CB_DEV_DOMAINS` / `CB_DEV_CERT_DAYS` 覆盖。例如生成一张即将过期的证书，以查看状态页的告警样式：

```bash
CB_DEV_CERT_DAYS=10 ./dev.sh reset
```

## 沙箱路径映射

插件中包含两套路径解析，规则一致：

- **shell（`scripts/renew.sh`、`event/started`）**：设置 `CB_DEV_ROOT` 后，所有绝对路径均加上该前缀，`/boot/config/...` 映射为 `$CB_DEV_ROOT/boot/config/...`。未设置时行为与真机一致。
- **PHP（`include/status.php` 等）**：`cb_syspath()` 按相同规则处理 `CB_PLUGIN_DIR`、`CB_CFG_DIR`、`CB_SSL_DIR`、`CB_VAR_INI` 和配置中的 `CERT_DIR`。

`dev/server.php` 启动时会自动设置 `CB_DEV_ROOT`、`CB_DOCKER` 和 `PATH`，子进程（`exec.php` 调起的 `renew.sh`）继承这些变量，因此从界面按钮和命令行触发时使用的是同一个沙箱。

## 假 docker

`dev/bin/docker` 仅实现 `info` / `image inspect` / `pull` / `run`：

- `docker run ... certonly` 不启动容器，而是使用 `openssl` 现场签发一张自签证书并写入 `live/<cert-name>/`，文件布局与真实 certbot 一致（`cert.pem` / `chain.pem` / `fullchain.pem` / `privkey.pem`）。
- 证书尚未到期且未携带 `--force-renewal` 时，与 certbot 一样输出 `Certificate not yet due for renewal` 并保持原状，用于验证「证书未变化」分支。
- 测试环境（`--server .../staging`）签发的证书，颁发者中包含 `STAGING` 字样。
- 凭据文件不存在时返回非零，用于验证错误分支。

`dev/bin/rc.nginx` 是 nginx 重启的假实现，仅打印一行输出。

## 与真机的差异

样式取自 Unraid 7，但预览环境属于仿真实现，并非 Unraid 的完整实现：

- `Wrappers.php` 仅是插件所用函数（`parse_plugin_cfg`、`mk_option`、`parse_cron_cfg`、`_`）的等价实现，并非 Unraid 原文件。
- `.page` 的 `_(标签)_:` 定义列表由本地实现解析，结构与 Unraid 的 php-markdown Extra 一致（`<dl>` 中一个 `<dt>` 后跟若干 `<dd>`），但未实现完整的 markdown 语法。`openBox()` / `addLog()` 使用原生 JS 重写。
- `dl/dt/dd` 使用浮动两列布局，而非 Unraid 的 `display:grid`。Unraid 的网格在「一个标签跟多个 `:` 定义」时会换行错位，本插件正是这种写法，因此按真机的实现意图排版。仅写一个 `:` 的页面两种做法显示一致。
- 顶栏右侧的服务器信息与页脚的阵列状态为示意内容，并非真实系统状态。
- `parse_cron_cfg()` 仅将 cron 写入沙箱的 `renew.cron`，不会修改 crontab。
- 假 docker 签发的是自签证书，浏览器不会信任。该环境仅验证流程，不验证证书链。
- 仅实现了插件使用的 CSS，并非 Unraid 主题的完整移植；未使用主题的图标字体（`fa-*` / `unraid`），标题图标以内联 SVG 代替。

## 环境变量

| 变量 | 作用 | 默认 |
|---|---|---|
| `CB_DEV_ROOT` | 沙箱根目录，映射所有系统绝对路径 | `dev/run` |
| `CB_DOCKER` | 替代 `docker` 可执行文件 | `$CB_DEV_ROOT/bin/docker` |
| `CB_DEV_PORT` | 预览端口 | `8080` |
| `CB_DEV_TZ` | 预览进程使用的时区，默认从 `/etc/localtime` 推断 | 系统时区 |
| `CB_DEV_HOST` | 样例主机名 | `tower` |
| `CB_DEV_EMAIL` | 样例邮箱 | `admin@example.com` |
| `CB_DEV_DOMAINS` | 样例域名列表 | `example.com,www.example.com` |
| `CB_DEV_CERT_DAYS` | 样例证书有效期 | `90` |

以上变量仅用于本地调试，真机上不设置，插件使用原本的绝对路径。
