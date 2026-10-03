# MOS-GOV V0.2.1 一体化 Windows 安装器

本安装器的目标是：**一台没有预装 ChurchCRM、PHP、Apache、MariaDB 的 Windows x64 电脑，也能从一个 Setup.exe 开始完成 MOS-GOV 本地部署。**

## V0.2.1 相对 V0.2 的修复

V0.2 的真实问题是**安装后入口链路**：

1. 桌面图标运行的是一个只含 `start http://...` 的 cmd 脚本——**不检查服务、不启动服务、不等待健康**。服务未运行（如重启后、端口被占、MariaDB 首启慢）时，用户只会在浏览器看到"无法访问此网站"。
2. 入口 URL 是**生成时固化的文本**，不读真实配置；一旦安装时 8080 被占用、安装器另选了端口，快捷方式永远打不开正确地址。
3. 双击后有一闪而过的黑色命令行窗口。
4. 失败时无任何提示，也无诊断工具。

V0.2.1 的修复：

| 修复 | 说明 |
| --- | --- |
| **MOSLauncher.exe**（MOS 平台启动器） | 从 `httpd.conf` / `my.ini` 读取**真实端口**；检查并以 UAC 提权方式自动启动 `MOS-GOV-MariaDB` / `MOS-GOV-Apache`；等待数据库 TCP 与平台 HTTP 健康（上限 60 秒）；健康后自动打开默认浏览器进入 `/plugins/mos-gov`（不可用时退回 `/session/begin`）；无任何命令行窗口。 |
| **失败可见** | 超时后显示中文错误窗口（服务状态 / 平台地址 / 最近 HTTP 结果），并提供"打开诊断信息"。 |
| **VC++ 运行库自愈** | Apache 无法启动且检测到缺少 VC++ 2015-2022 x64 运行库时，静默安装内置的 `prereqs\vc_redist.x64.exe` 后重试。 |
| **桌面 / 开始菜单快捷方式** | 名称统一为 **MOS 平台**，Target=`MOSLauncher.exe`，图标=`MOS.ico`（16-256 全尺寸正式图标）。 |
| **安装完成页** | 提供 **[立即打开 MOS 平台]**，直接调用 MOSLauncher.exe。 |
| **MOS 诊断** | 开始菜单提供 `MOS 诊断`（`MOS-Diagnose.cmd`），一键输出 PASS/WARN/FAIL 报告到桌面 `MOS-GOV-Diagnostics.txt`。 |
| **注册表锚点** | 写入 `HKLM\SOFTWARE\MOS-GOV\InstallPath`，启动器升级/换目录后仍能定位。 |

用户唯一需要的操作：**安装 → 桌面双击【MOS 平台】→ 自动进入平台。**

## 自动部署内容

- ChurchCRM 7.7.0
- PHP 8.4.25 Thread Safe x64
- Apache HTTP Server 2.4.68 Win64
- MariaDB 11.8.9 Win64
- MOS-GOV V0.2
- MOS-GOV 正式数据库迁移
- 本地安全模式
- Windows 服务

安装后的程序位于：

C:\Program Files\MOS-GOV

教会数据库、安装状态和日志位于：

C:\ProgramData\MOS-GOV

默认情况下，Web 与 MariaDB 都只绑定到 127.0.0.1；安装器会在预设范围内自动选择空闲端口。

安装器支持“新教会”模式：如果本机已经存在 MOS-GOV 数据，重新运行 Setup 时会明确询问是否清除旧数据库。确认后，旧 MariaDB 数据目录会先移动到：

C:\\ProgramData\\MOS-GOV\\backups

然后以相同端口重新建立一个全新的 ChurchCRM + MOS-GOV 环境。不会静默覆盖旧教会数据。

## 用户体验

用户无需预先安装：

- ChurchCRM
- PHP
- Apache
- MariaDB
- Docker
- Git
- Node.js

安装完成后自动打开本机 MOS-GOV。

ChurchCRM 的首次登录仍使用其本身的首次安装流程，并要求首次登录后的管理员账号完成密码设置与教会基本资料设置；安装器不把开发/验收测试身份或测试数据写入正式环境。

## 构建

在 Windows 上安装 Inno Setup 6，然后执行：

powershell -ExecutionPolicy Bypass -File installer\build.ps1

构建脚本会下载并校验固定版本的 ChurchCRM、PHP、Apache、MariaDB，并校验 Microsoft Visual C++ Redistributable 的 Authenticode 签名，然后生成：

dist\MOS-GOV-V0.2-Setup.exe

以及：

dist\MOS-GOV-V0.2-Setup.sha256.json

## 正式安装包不包含

- Acceptance Fixture
- T01/T02/T03/T04
- API Key
- 测试状态文件
- 数据库备份
- 本机开发路径
- Docker 数据

## 卸载

卸载时只停止并删除 MOS-GOV 自己创建的 Windows 服务。

默认保留：

C:\ProgramData\MOS-GOV

这样不会因为卸载程序而删除教会数据。

## 新建教会 / 清除旧数据

安装完成后，开始菜单和桌面都会提供：

**MOS-GOV 新建教会**

运行后需要管理员权限，并要求输入 `NEW CHURCH` 确认。程序会：

1. 停止 MOS-GOV 服务；
2. 将旧数据库目录移入本机备份目录；
3. 重新初始化 MariaDB；
4. 重新导入 ChurchCRM 初始数据库；
5. 重新导入 MOS-GOV 数据表；
6. 保留原来的程序目录、端口和本地部署方式。

因此，同一台电脑可以依次用于不同教会，而不需要重新下载整套运行环境。

## 构建基线

MOS-GOV 中文体验冻结点：7b0b8e8

权限语义修复：44941a7

标准验收 Fixture：be0dbf6

ChurchCRM 组件版本固定于 7.7.0，PHP 固定于 8.4.25，Apache 固定于 2.4.69（ApacheLounge VS18 Win64；2.4.68 上游镜像已 410 下架，V0.2.1 起改用 2.4.69），MariaDB 固定于 11.8.9。

安装器是独立部署工程，不修改 ChurchCRM Core。
