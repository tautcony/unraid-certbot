# unraid-certbot

通过 Cloudflare DNS-01 验证，为 Unraid webGUI 自动签发和续期 Let's Encrypt 证书。

## 安装

需要 Unraid 6.9 或更高版本：

```bash
plugin install https://raw.githubusercontent.com/tautcony/unraid-certbot/master/unraid-certbot.plg
```

## 配置

入口：**设置 → 网络服务 → Unraid Certbot**。页面分为四个标签：证书状态、设置、续期历史、运行日志。

### Cloudflare API Token

在 Cloudflare 后台 **My Profile → API Tokens → Create Token** 创建，权限要求：

- **Permissions**：`Zone` → `DNS` → `Edit`
- **Zone Resources**：`Include` → `Specific zone`，选择证书域名所属的 zone

### 配置项

| 配置项 | 说明 |
|---|---|
| 界面语言 | 跟随 Unraid（默认）、简体中文或 English，仅影响本插件界面 |
| Cloudflare API Token | 用于 DNS-01 验证，保存于 `cloudflare.ini`，权限 600 |
| 邮箱 | 接收 Let's Encrypt 证书到期提醒 |
| Unraid 主机名 | 必须与 Unraid 服务器名称一致，否则 webGUI 不会加载新证书 |
| 域名列表 | 逗号或换行分隔；第一个为主域名；支持通配符（如 `*.example.com`） |
| DNS 传播等待秒数 | DNS 记录创建后的等待时间，默认 60 秒 |
| 证书存储目录 | 默认 `/mnt/user/appdata/letsencrypt`，必须位于支持符号链接的文件系统，FAT/exFAT 会导致续期失败 |
| 自动续期频率 | 检查频率，默认每天；证书到期前 30 天内才实际续期 |
| 更新后重启 nginx | 证书变化后重启 webGUI 服务，默认开启 |
| 测试环境 | 使用 Let's Encrypt Staging 环境，签发的证书不受浏览器信任 |

首次使用建议先启用「测试环境」验证流程：Staging 环境速率限制宽松，适合验证 Token 权限与域名配置。验证通过后取消勾选，执行「强制续期」获取正式证书。

## 文件位置

| 路径 | 内容 |
|---|---|
| `/boot/config/plugins/unraid-certbot/` | 配置、Token、续期历史、日志、定时任务 |
| `/mnt/user/appdata/letsencrypt/`（默认） | certbot 账号、证书与续期配置 |
| `/boot/config/ssl/certs/<主机名>_unraid_bundle.pem` | webGUI 使用的证书 |

## 排障

```bash
# 查看当前状态（只读，不做变更）
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --status

# 手动执行一次续期检查
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --trigger=manual

# 强制续期
/usr/local/emhttp/plugins/unraid-certbot/scripts/renew.sh --force --trigger=manual

# 查看日志
tail -50 /boot/config/plugins/unraid-certbot/certbot.log
```

脚本退出码：`1` 配置错误，`2` certbot 执行失败，`3` 已有实例正在运行，`4` Docker 不可用。

常见故障：

- **zone_id 错误**：Token 权限不足，或域名不属于 Token 授权的 zone。
- **Docker 服务未运行**：阵列未启动，或 Docker 服务不可用。
- **webGUI 仍使用旧证书**：主机名与 Unraid 服务器名称不一致，或未启用「更新后重启 nginx」。
- **续期成功但浏览器仍提示过期**：浏览器缓存，稍后刷新。

## 开发

源码位于 `source/unraid-certbot/`，目录结构映射到 Unraid 的 `/usr/local/emhttp/`。

```bash
./lint.sh               # 静态检查（与 CI 相同）
tools/build.sh --local  # 打包当前工作区，供本地测试
tools/build.sh          # 从已提交源码构建可复现包
tools/release-new.sh    # 将当前 VERSION 作为新 tag 发布
tools/republish-old.sh 2026.09.25 # 重发已有版本
./dev.sh                # 启动本地预览：http://127.0.0.1:8080
```

本地调试与沙箱结构见 [dev/README.md](dev/README.md)。
打包与发布命令见 [tools/README.md](tools/README.md)。

发布：先提交源码、构建脚本和 `VERSION`，再运行 `tools/build.sh`，即可与 CI 的包比较 SHA256。`tools/release-new.sh` 推送新 tag 后自动触发流水线。`tools/republish-old.sh` 针对已存在的 tag 补发缺失的 Release 资产，不会把旧版 `.plg` 写回默认分支；已发布的资产不覆盖。本地构建需要 Docker。

## 许可

GPL-3.0-or-later，见 [LICENSE](LICENSE)。

Copyright (C) 2026 tautcony
