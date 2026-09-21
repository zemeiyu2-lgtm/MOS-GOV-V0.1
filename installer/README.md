# MOS-GOV V0.2 一体化 Windows 安装器

本安装器的目标是：**一台没有预装 ChurchCRM、PHP、Apache、MariaDB 的 Windows x64 电脑，也能从一个 Setup.exe 开始完成 MOS-GOV 本地部署。**

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

## 构建基线

MOS-GOV 中文体验冻结点：7b0b8e8

权限语义修复：44941a7

标准验收 Fixture：be0dbf6

ChurchCRM 组件版本固定于 7.7.0，PHP 固定于 8.4.25，Apache 固定于 2.4.68，MariaDB 固定于 11.8.9。

安装器是独立部署工程，不修改 ChurchCRM Core。
