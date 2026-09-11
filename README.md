# unraid-certbot

通过 Cloudflare DNS-01 验证，为 Unraid webGUI 自动签发和续期 Let's Encrypt 证书。

设置页用于配置 Cloudflare Token、邮箱、主机名、域名和续期策略，并显示证书状态、续期历史与运行日志；定时任务负责证书状态检查和自动续期。

## 安装

需要 Unraid 6.9 或更高版本。该地址适用于命令行安装与 **Plugins → Install Plugin** 页面安装：

```bash
plugin install https://raw.githubusercontent.com/tautcony/unraid-certbot/master/unraid-certbot.plg
```

## 配置

### Cloudflare API Token

Token 用于在 DNS-01 验证期间创建和删除 DNS 记录，权限要求如下：

- **Permissions**：`Zone` → `DNS` → `Edit`
- **Zone Resources**：`Include` → `Specific zone`，范围限定为证书域名所属的 zone

### 配置项

配置入口：**Settings → Unraid Certbot**。

| 配置项 | 说明 |
|---|---|
| Cloudflare API Token | 用于 DNS-01 验证。保存于 `/boot/config/plugins/unraid-certbot/cloudflare.ini`，权限 600。 |
| 邮箱 | Let's Encrypt 发送证书到期提醒的地址。 |
| Unraid 主机名 | 必须与 Unraid 服务器名称一致，用于确定 webGUI 证书的文件名。 |
| 域名列表 | 证书包含的域名，以逗号或换行分隔。**第一个域名为主域名**，用于确定证书目录名称和 bundle 文件内容。 |
| DNS 传播等待秒数 | certbot 创建 DNS 记录后等待 DNS 生效的时间，默认 60 秒。 |
| 证书存储目录 | certbot 账号、证书和续期配置的存储位置，默认 `/boot/config/letsencrypt`。指向已有的 certbot 目录可复用账号和证书。 |
| 自动续期频率 | 定时检查频率，默认每天检查一次。是否续期由 certbot 根据证书有效期决定。 |
| 阵列启动后自动检查 | 阵列启动完成后延迟执行一次检查，默认关闭。适用于证书过期导致 webGUI 报警的环境。 |
| 更新后重启 nginx | 证书内容变化后重启 nginx，默认开启。证书未变化时不重启。 |
| 测试环境 | 是否使用 Let's Encrypt Staging 环境，默认关闭。该环境签发的证书不受浏览器信任。 |

Unraid 仅识别文件名为 `/boot/config/ssl/certs/<主机名>_unraid_bundle.pem` 的证书文件。主机名不一致时，webGUI 不会加载新证书。Unraid 使用的主机名来源于 `/var/local/emhttp/var.ini` 中的 `NAME` 字段。

### 测试环境

Staging 环境的速率限制较宽松，适用于验证 Token 权限、域名配置和 DNS 传播时间。该环境签发的证书不受浏览器信任，正式使用时须在生产环境重新签发证书。

## 文件位置

| 路径 | 内容 |
|---|---|
| `/boot/config/plugins/unraid-certbot/unraid-certbot.cfg` | 用户配置 |
| `/boot/config/plugins/unraid-certbot/cloudflare.ini` | Cloudflare Token（600 权限） |
| `/boot/config/plugins/unraid-certbot/history.tsv` | 续期历史（最近 200 条） |
| `/boot/config/plugins/unraid-certbot/certbot.log` | 运行日志（超过 1 MiB 自动截断） |
| `/boot/config/plugins/unraid-certbot/renew.cron` | 定时任务 |
| `/boot/config/letsencrypt/` | certbot 账号、证书、续期配置 |
| `/boot/config/ssl/certs/<主机名>_unraid_bundle.pem` | webGUI 实际使用的证书 |

配置、日志和历史记录默认存储于 `/boot`。该位置支持在阵列停止时执行续期，适用于 webGUI 无法正常访问的场景。证书目录可使用 appdata 路径；日志和历史记录仍保存在 `/boot`，每次运行仅追加一条记录。

## 排障

「检查 Docker 环境」功能输出 Docker 服务和镜像状态。相关命令如下：

```bash
# 以只读方式查看当前状态，不执行变更
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --status

# 手动执行一次并显示完整输出
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --trigger=manual

# 强制续期
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --force --trigger=manual

# 查看日志
tail -50 /boot/config/plugins/unraid-certbot/certbot.log

# 确认 cron 是否已注册
crontab -l -c /etc/cron.d | grep unraid-certbot
```

脚本退出码：`1` 表示配置错误，`2` 表示 certbot 执行失败，`3` 表示已有实例正在运行，`4` 表示 Docker 不可用。

常见故障及原因：

- **certbot 报告 zone_id 错误**：Token 权限不满足 DNS 编辑要求，或域名不属于 Token 授权的 zone。
- **Docker 服务未运行**：阵列未启动，或 Docker 服务不可用。
- **webGUI 仍使用旧证书**：主机名与 Unraid 服务器名称不一致，或未启用「更新后重启 nginx」。
- **证书已续期但浏览器仍提示过期**：浏览器缓存尚未更新。

## 开发

源码位于 `source/unraid-certbot/`，目录结构直接映射到 Unraid 上的 `/usr/local/emhttp/`。

本地检查与打包使用以下命令：

```bash
./lint.sh               # shell / php / xml 静态检查，与 CI 使用同一份脚本
./build.sh              # 使用 VERSION 中的版本号打包
./build.sh 2026.10.01   # 指定版本号
```

命令生成 `dist/unraid-certbot-<版本>-noarch-1.txz`，并将版本号及 MD5/SHA256 校验和写入 `unraid-certbot.plg`。

### 本地调试

`./dev.sh` 会创建沙箱（`dev/run/`）并启动预览服务器，用于在开发机检查界面与流程：

```bash
./dev.sh                 # 初始化沙箱并启动预览：http://127.0.0.1:8080
./dev.sh status          # 在沙箱中执行 renew.sh --status
./dev.sh renew --force   # 在沙箱中执行强制续期（假 docker 现场签发自签证书）
./dev.sh reset           # 重建沙箱
```

### 发布

发布由 `.github/workflows/release.yml` 自动执行，触发条件为推送 `YYYY.MM.DD` 格式的 tag：

```bash
git tag 2026.09.10
git push origin 2026.09.10
```

工作流依次执行以下操作：运行 `lint.sh`；以 tag 作为版本号打包；校验 `.plg` 中的校验和与实际包一致；创建 Release 并上传 `.txz`；将已更新版本号和校验和的 `.plg` 提交到默认分支。

安装地址始终指向默认分支上的 `.plg`：

```
https://raw.githubusercontent.com/tautcony/unraid-certbot/master/unraid-certbot.plg
```

版本号必须使用 `YYYY.MM.DD` 格式，因为 Unraid 使用 `strcmp` 而非语义化版本规则比较版本号。

## 许可

GPL-3.0-or-later。本项目按 GNU 通用公共许可证第 3 版或任何更新版本（由使用者选择）授权，完整条款见 `LICENSE`。

Copyright (C) 2026 tautcony
