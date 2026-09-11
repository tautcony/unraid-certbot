# 本地调试

在开发机预览插件界面并运行续期流程，所有读写都在 `dev/run/` 沙箱内，不影响宿主机。

## 快速开始

```bash
./dev.sh                # 初始化沙箱并启动预览服务器
```

启动后访问 <http://127.0.0.1:8080>：

| 地址 | 内容 |
|---|---|
| `/` | 调试首页，显示沙箱状态与快捷入口 |
| `/Settings/UnraidCertbot` | 设置页（证书状态 / 设置 / 续期历史 / 运行日志） |
| `/Utilities/CertStatus` | 兼容地址，302 跳转到证书状态标签 |

常用命令：

```bash
./dev.sh status          # 查看当前状态
./dev.sh renew           # 执行一次续期
./dev.sh renew --force   # 强制续期
./dev.sh reset           # 重建沙箱
./dev.sh doctor          # 检查本机依赖
./dev.sh --port 9000     # 指定端口
```

## 沙箱结构

`dev/run/` 为运行时目录（已加入 `.gitignore`），结构与 Unraid 对应。插件目录通过软链指向 `source/`，改源码立即生效。证书签发由 `dev/bin/docker` 模拟（基于 openssl 自签，不联网），nginx 重启由 `dev/bin/rc.nginx` 模拟。

样例数据默认为主机名 `tower`、域名 `example.com,www.example.com`，可通过环境变量覆盖：

```bash
CB_DEV_CERT_DAYS=10 ./dev.sh reset    # 生成即将过期的证书以查看告警样式
```

| 变量 | 作用 | 默认 |
|---|---|---|
| `CB_DEV_ROOT` | 沙箱根目录 | `dev/run` |
| `CB_DOCKER` | docker 可执行文件 | `$CB_DEV_ROOT/bin/docker` |
| `CB_DEV_PORT` | 预览端口 | `8080` |
| `CB_DEV_TZ` | 时区 | 系统时区 |
| `CB_DEV_HOST` | 样例主机名 | `tower` |
| `CB_DEV_EMAIL` | 样例邮箱 | `admin@example.com` |
| `CB_DEV_DOMAINS` | 样例域名 | `example.com,www.example.com` |
| `CB_DEV_CERT_DAYS` | 样例证书有效期 | `90` |

以上变量仅用于本地调试。
