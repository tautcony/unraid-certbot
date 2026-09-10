# 本地调试

这套东西让你在开发机上（macOS / Linux）预览插件界面、跑通续期流程，而**不会**
碰到宿主机的 `/boot`、`/usr/local/emhttp`，也不会真的调用 Docker 或 Let's Encrypt。

## 快速开始

```bash
./dev.sh                # 初始化沙箱 + 启动预览服务器
```

然后打开 <http://127.0.0.1:8080>：

| 地址 | 内容 |
|---|---|
| `/` | 调试首页，显示沙箱状态和快捷入口 |
| `/Settings/UnraidCertbot` | 设置页，对应真机的 Settings → Unraid Certbot |
| `/Utilities/CertStatus` | 状态页，对应 Utilities → Cert Status |

设置页上的「立即检查并续期」「强制续期」按钮会真的执行 `scripts/renew.sh`，
只是 `docker` 被换成了不联网的假实现。

预览界面的外壳和控件样式抄自 Unraid 7 的 webGUI 源码（见下文「界面还原度」），
所以看起来应该和真机的 webGUI 基本一致。

常用命令：

```bash
./dev.sh status          # renew.sh --status，查看沙箱里解析出来的配置
./dev.sh renew           # 跑一次续期（证书没到期时走「未变化」分支）
./dev.sh renew --force   # 强制续期，会重新签发并覆盖 bundle
./dev.sh reset           # 删掉 dev/run 重建（配置和证书一起重置）
./dev.sh doctor          # 检查本机依赖
./dev.sh --port 9000     # 换端口
./dev/seed.sh --quiet    # 只初始化沙箱，不打印进度
```

## 界面还原度

外壳（顶栏、菜单、`div.title`、页脚）和控件（`dl` 两列表单、表格、输入框、
下拉框、按钮、`blockquote`、标签页）的样式直接取自 Unraid 7 的 webGUI 源码：

| Unraid 文件 | 用到的部分 |
|---|---|
| `dynamix/styles/default-color-palette.css` | `--gray-*`、`--orange-*`、`--brand-*` 等调色板 |
| `dynamix/styles/themes/black.css` | 默认主题：深色正文 + 浅色顶栏 |
| `dynamix/styles/themes/white.css` | 白色主题：浅色正文 + 深色顶栏 |
| `dynamix/styles/default-base.css` | `#header` / `#menu` / `.nav-item` / `div.title` / `dl,dt,dd` / 表格 / 表单 / 页脚 |
| `dynamix/include/DefaultPageLayout/Header.php` | UNRAID wordmark 的 SVG 路径与红→橙渐变 |
| `dynamix/include/DefaultPageLayout/{MainContent,Footer}.php` | 内容与页脚结构 |
| `dynamix/include/MarkdownExtra.php` | 定义列表渲染成 `<dl><dt>…</dt><dd>…</dd>` 的结构（本地用等价实现） |

几个和真机一致的细节：

- 默认是 Unraid 的 **white** 主题（深色顶栏 `#1d1b1b` + 浅色正文 `#f2f2f2`），
  和真机中文界面截图一致；页脚的「主题」按钮可以切到 black（深色正文 + 浅色顶栏），
  选择存在浏览器 localStorage 里，也可以用 `?theme=black` 一次性指定。
- 顶部菜单用真机中文语言包的叫法（仪表板 / 主界面 / 共享 / 用户 / 设置 / 插件 /
  DOCKER / 虚拟机 / 应用 / 工具），当前页有下划线；预览里真实存在的页面才可点。
- **说明文字默认隐藏**——这是 Unraid 7 的行为（`.inline_help { display: none }`）。
  点击表单标签可以展开该条说明，点顶部 **帮助** 可以一次展开/收起全部；
  `?help=1` 直接全部展开。
- 「应用」按钮默认禁用，表单有改动才启用；禁用态是灰色描边，可点态是橙红渐变描边。
- 表格是浅色斑马纹 + 大写灰色表头，输入框是下划线样式，标题条用
  `--title-header-background-color`（gray-150）。

## 沙箱

`dev/run/` 是运行时目录（已在 `.gitignore` 里），结构刻意做成和 Unraid 一致：

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

样例数据默认是主机名 `tower`、域名 `example.com,www.example.com`，
可以用 `CB_DEV_HOST` / `CB_DEV_EMAIL` / `CB_DEV_DOMAINS` / `CB_DEV_CERT_DAYS` 覆盖，
例如造一张快过期的证书来看状态页的告警样式：

```bash
CB_DEV_CERT_DAYS=10 ./dev.sh reset
```

## 插件是怎么被“骗”到沙箱里的

插件里有两种路径解析，规则是同一套：

- **shell（`scripts/renew.sh`、`event/started`）**：设置 `CB_DEV_ROOT` 后，
  所有绝对路径都会加上这个前缀。`/boot/config/...` → `$CB_DEV_ROOT/boot/config/...`。
  没设置时行为和真机完全一样。
- **PHP（`include/status.php` 等）**：`cb_syspath()` 用同样的规则处理
  `CB_PLUGIN_DIR`、`CB_CFG_DIR`、`CB_SSL_DIR`、`CB_VAR_INI` 和配置里的 `CERT_DIR`。

`dev/server.php` 启动时会自己补上 `CB_DEV_ROOT`、`CB_DOCKER` 和 `PATH`，
子进程（`exec.php` 调起的 `renew.sh`）继承这些变量，所以从界面点按钮和从
命令行跑，看到的是同一个沙箱。

## 假 docker

`dev/bin/docker` 只实现 `info` / `image inspect` / `pull` / `run`：

- `docker run ... certonly` 不启动容器，而是用 `openssl` 现场签一张自签证书，
  写到 `live/<cert-name>/` 下，文件布局与真实 certbot 一致
  （`cert.pem` / `chain.pem` / `fullchain.pem` / `privkey.pem`）。
- 证书还早着到期、又没带 `--force-renewal` 时，会像 certbot 一样输出
  `Certificate not yet due for renewal` 并保持原样，用来验证「证书未变化」分支。
- 测试环境（`--server .../staging`）签出的证书颁发者里带 `STAGING` 字样。
- 凭据文件不存在时会返回非零，用来验证错误分支。

`dev/bin/rc.nginx` 是假的 nginx 重启，只打印一行。

## 与真机的差异

样式是按 Unraid 7 抄的，但预览终究是仿真，别把它当成 Unraid 的完整实现：

- `Wrappers.php` 只是插件用到的那几个函数（`parse_plugin_cfg`、`mk_option`、
  `parse_cron_cfg`、`_`）的等价实现，不是 Unraid 原文件。
- `.page` 的 `_(标签)_:` 定义列表由本地实现解析，结构与 Unraid 的
  php-markdown Extra 一致（`<dl>` 里一个 `<dt>` 跟若干 `<dd>`），但没有实现完整的
  markdown 语法；`openBox()` / `addLog()` 用原生 JS 重写。
- `dl/dt/dd` 用浮动两列布局，而不是 Unraid 的 `display:grid`：Unraid 的网格在
  「一个标签跟多个 `:` 定义」时会换行错位，本插件正好是这种写法，所以这里按
  真机的**意图**排版。只写一个 `:` 的页面两种做法看起来一样。
- 顶栏右侧的服务器信息、页脚的阵列状态是示意内容，不是真实系统状态。
- `parse_cron_cfg()` 只把 cron 写进沙箱的 `renew.cron`，不会去动 crontab。
- 假 docker 签的是自签证书，浏览器一定不信任；这里只验证流程，不验证证书链。
- 只实现了插件用到的 CSS，不是 Unraid 主题的完整移植，没用到主题的图标字体
  （`fa-*` / `unraid` 字体），标题图标用内联 SVG 代替。

## 环境变量

| 变量 | 作用 | 默认 |
|---|---|---|
| `CB_DEV_ROOT` | 沙箱根目录，映射所有系统绝对路径 | `dev/run` |
| `CB_DOCKER` | 替代 `docker` 可执行文件 | `$CB_DEV_ROOT/bin/docker` |
| `CB_DEV_PORT` | 预览端口 | `8080` |
| `CB_DEV_TZ` | 预览进程用的时区，默认从 `/etc/localtime` 推断 | 系统时区 |
| `CB_DEV_HOST` | 样例主机名 | `tower` |
| `CB_DEV_EMAIL` | 样例邮箱 | `admin@example.com` |
| `CB_DEV_DOMAINS` | 样例域名列表 | `example.com,www.example.com` |
| `CB_DEV_CERT_DAYS` | 样例证书有效期 | `90` |

这些都是**本地调试专用**，真机上不设置，插件走原本的绝对路径。
