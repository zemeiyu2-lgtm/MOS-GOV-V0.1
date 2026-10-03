# -*- coding: utf-8 -*-
"""MOSLauncher - MOS 平台启动器

双击入口：检查并启动 MOS-GOV 服务 -> 等待平台健康 -> 自动打开默认浏览器。
不依赖 PowerShell / 命令行窗口；失败时显示中文错误窗口并提供诊断入口。
"""
import os
import sys
import socket
import subprocess
import ctypes
import time
import webbrowser
import winreg
from pathlib import Path

IS_FROZEN = getattr(sys, "frozen", False)

SERVICE_APACHE = "MOS-GOV-Apache"
SERVICE_MARIA = "MOS-GOV-MariaDB"
TIMEOUT_SECONDS = 60

# ctypes 服务控制（避免依赖 PowerShell / sc.exe）
SC_MANAGER_CONNECT = 0x0001
SC_MANAGER_ALL_ACCESS = 0xF003F
SERVICE_ALL_ACCESS = 0xF01FF
SERVICE_QUERY_STATUS = 0x0004
SERVICE_START = 0x0010

advapi32 = ctypes.WinDLL("advapi32", use_last_error=True)
shell32 = ctypes.WinDLL("shell32", use_last_error=True)
user32 = ctypes.WinDLL("user32", use_last_error=True)


class SERVICE_STATUS(ctypes.Structure):
    _fields_ = [
        ("dwServiceType", ctypes.c_uint32),
        ("dwCurrentState", ctypes.c_uint32),
        ("dwControlsAccepted", ctypes.c_uint32),
        ("dwWin32ExitCode", ctypes.c_uint32),
        ("dwServiceSpecificExitCode", ctypes.c_uint32),
        ("dwCheckPoint", ctypes.c_uint32),
        ("dwWaitHint", ctypes.c_uint32),
    ]


def service_state(name):
    """返回服务状态字符串；未安装返回 None；无法访问时返回 UNKNOWN。
    使用 SC_MANAGER_CONNECT，保证普通（非提升）用户也能查询服务状态。
    仅当系统明确报告 ERROR_SERVICE_DOES_NOT_EXIST (1060) 时才视为未安装。"""
    h_scm = advapi32.OpenSCManagerW(None, None, SC_MANAGER_CONNECT)
    if not h_scm:
        return "UNKNOWN"
    try:
        h_svc = advapi32.OpenServiceW(h_scm, name, SERVICE_QUERY_STATUS)
        if not h_svc:
            if ctypes.get_last_error() == 1060:  # ERROR_SERVICE_DOES_NOT_EXIST
                return None
            return "UNKNOWN"
        try:
            ss = SERVICE_STATUS()
            if advapi32.QueryServiceStatus(h_svc, ctypes.byref(ss)):
                mapping = {
                    1: "STOPPED", 2: "START_PENDING", 3: "STOP_PENDING",
                    4: "RUNNING", 5: "CONTINUE_PENDING", 6: "PAUSE_PENDING",
                    7: "PAUSED",
                }
                return mapping.get(ss.dwCurrentState, "UNKNOWN")
            return None
        finally:
            advapi32.CloseServiceHandle(h_svc)
    finally:
        advapi32.CloseServiceHandle(h_scm)


def service_start(name):
    """尝试启动服务；权限不足时通过 UAC 提权执行 net start。返回是否成功。"""
    h_scm = advapi32.OpenSCManagerW(None, None, SC_MANAGER_CONNECT)
    if not h_scm:
        ret = shell32.ShellExecuteW(None, "runas", "net.exe", "start " + name, None, 0)
        return ret > 32
    try:
        h_svc = advapi32.OpenServiceW(h_scm, name, SERVICE_START)
        if not h_svc:
            # 权限不足 -> 提权
            ret = shell32.ShellExecuteW(None, "runas", "net.exe", "start " + name, None, 0)
            return ret > 32
        try:
            if advapi32.StartServiceW(h_svc, 0, None):
                return True
            err = ctypes.get_last_error()
            if err == 1056:  # 已在运行
                return True
            advapi32.CloseServiceHandle(h_svc)
            # 退回提权方式
            ret = shell32.ShellExecuteW(None, "runas", "net.exe", "start " + name, None, 0)
            return ret > 32
        finally:
            advapi32.CloseServiceHandle(h_svc)
    finally:
        advapi32.CloseServiceHandle(h_scm)


def resolve_install_dir():
    if not IS_FROZEN:
        # 源码运行：runtime 目录的父目录
        parent = Path(__file__).resolve().parent.parent
        if (parent / "Apache24" / "bin" / "httpd.exe").exists():
            return str(parent)
    # 注册表（安装器写入）
    for key in (r"SOFTWARE\MOS-GOV", r"SOFTWARE\WOW6432Node\MOS-GOV"):
        try:
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, key) as k:
                val, _ = winreg.QueryValueEx(k, "InstallPath")
                if val and Path(val, "Apache24", "bin", "httpd.exe").exists():
                    return val
        except OSError:
            pass
    return r"C:\Program Files\MOS-GOV"


def parse_http_endpoint(install_dir):
    """从 httpd.conf 读取真实 Listen 地址与端口。"""
    host, port = "127.0.0.1", None
    conf = Path(install_dir, "Apache24", "conf", "httpd.conf")
    try:
        for raw in conf.read_text(encoding="utf-8", errors="replace").splitlines():
            line = raw.strip()
            if line.startswith("#") or not line.lower().startswith("listen"):
                continue
            spec = line[6:].strip()
            if ":" in spec:
                h, _, p = spec.rpartition(":")
                host, port = h.strip(), int(p)
            elif spec.isdigit():
                host, port = "0.0.0.0", int(spec)
            if port:
                break
    except OSError:
        pass
    if not port:
        host, port = "127.0.0.1", 8080
    if host in ("0.0.0.0", "*", "_default_"):
        host = "127.0.0.1"
    return host, port


def parse_db_port(program_data):
    """从 my.ini 读取 MariaDB 端口。"""
    myini = Path(program_data, "data", "my.ini")
    try:
        in_mysqld = False
        for raw in myini.read_text(encoding="utf-8", errors="replace").splitlines():
            line = raw.strip()
            if line.startswith("[") and line.endswith("]"):
                in_mysqld = line.lower() == "[mysqld]"
                continue
            if in_mysqld and line.lower().startswith("port"):
                _, _, val = line.partition("=")
                val = val.strip()
                if val.isdigit():
                    return int(val)
    except OSError:
        pass
    return 3306


def tcp_open(host, port, timeout=1.5):
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False


def http_status(url, timeout=2.5):
    """用原生 socket 发送 HTTP GET，返回状态码；连接失败返回 0。
    不走系统/企业代理（urllib 会被代理拦截导致本机探测永远失败）。"""
    try:
        from urllib.parse import urlparse
        u = urlparse(url)
        host, port = u.hostname, u.port or 80
        path = (u.path or "/") + (("?" + u.query) if u.query else "")
        s = socket.create_connection((host, port), timeout=timeout)
        try:
            s.settimeout(timeout)
            s.sendall(("GET {} HTTP/1.1\r\nHost: {}:{}\r\n"
                       "User-Agent: MOSLauncher/1.0\r\nConnection: close\r\n\r\n"
                       ).format(path, host, port).encode("ascii"))
            data = b""
            while len(data) < 4096:
                chunk = s.recv(1024)
                if not chunk:
                    break
                data += chunk
            line = data.split(b"\r\n", 1)[0].decode("latin-1")
            parts = line.split()
            if len(parts) >= 2 and parts[0].startswith("HTTP/"):
                return int(parts[1])
            return 0
        finally:
            s.close()
    except Exception:
        return 0


def is_admin():
    try:
        return ctypes.windll.shell32.IsUserAnAdmin() != 0
    except Exception:
        return False


def check_vc_redist():
    for key in (r"SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64",
                r"SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\x64"):
        try:
            with winreg.OpenKey(winreg.HKEY_LOCAL_MACHINE, key) as k:
                installed, _ = winreg.QueryValueEx(k, "Installed")
                if installed == 1:
                    return True
        except OSError:
            pass
    return False


def install_vc_redist(install_dir):
    vc = Path(install_dir, "prereqs", "vc_redist.x64.exe")
    if not vc.exists():
        return False
    ret = shell32.ShellExecuteW(None, "runas", str(vc), "/install /quiet /norestart", None, 0)
    if ret > 32:
        # 等待安装完成（最多 120 秒）
        for _ in range(60):
            time.sleep(2)
            if check_vc_redist():
                return True
    return False


# ---------------------------------------------------------------- UI
def _find_progress_hwnd():
    """按唯一标题查找进度 MessageBox 窗口句柄。"""
    return user32.FindWindowW(None, "MOS 平台启动中")


def make_progress_window():
    """在后台线程显示一个原生 MessageBox 作为进度提示（不依赖 tkinter）。
    返回 (progress_obj, None)。调用 progress_obj.close() 关闭。"""
    import threading

    class _Progress(object):
        def close(self):
            try:
                hwnd = _find_progress_hwnd()
                if hwnd:
                    user32.SendMessageW(hwnd, 0x0010, 0, 0)  # WM_CLOSE
            except Exception:
                pass

    t = threading.Thread(target=user32.MessageBoxW,
                         args=(None, "正在启动 MOS 平台…\n\n首次启动通常需要 10–60 秒，请稍候。",
                               "MOS 平台启动中", 0x00000040),
                         daemon=True)
    t.start()
    time.sleep(0.6)  # 给窗口一点时间出现
    return _Progress(), None


def show_error(install_dir, lines):
    """原生错误对话框：确定=打开诊断信息，取消=关闭。"""
    text = ("MOS平台暂时无法启动。\n\n" + "\n".join(lines) +
            "\n\n点击【确定】打开诊断信息，点击【取消】关闭。")
    # MB_OKCANCEL | MB_ICONERROR | MB_SETFOREGROUND | MB_TOPMOST
    result = user32.MessageBoxW(None, text, "MOS 平台",
                                0x00000001 | 0x00000010 | 0x00010000 | 0x00040000)
    if result == 1:  # IDOK
        try:
            diag = Path(install_dir, "runtime", "MOS-Diagnose.cmd")
            if diag.exists():
                os.startfile(str(diag))
        except Exception:
            pass


def write_log(log_dir, msg):
    try:
        log_dir.mkdir(parents=True, exist_ok=True)
        with open(log_dir / "launcher.log", "a", encoding="utf-8") as f:
            f.write(time.strftime("[%Y-%m-%d %H:%M:%S] ") + msg + "\n")
    except OSError:
        pass


def main():
    install_dir = resolve_install_dir()
    program_data = Path(os.environ.get("ProgramData", r"C:\ProgramData"), "MOS-GOV")
    log_dir = program_data / "logs"
    write_log(log_dir, "=== MOSLauncher start ===")
    write_log(log_dir, "install dir: " + install_dir)

    if not Path(install_dir, "Apache24", "bin", "httpd.exe").exists():
        write_log(log_dir, "install dir invalid")
        show_error(install_dir, [
            "未检测到 MOS 平台安装目录。",
            "安装目录：" + install_dir,
            "请先运行 MOS 平台安装程序。",
        ])
        return 10

    host, port = parse_http_endpoint(install_dir)
    db_port = parse_db_port(program_data)
    base_url = "http://{}:{}".format(host, port)
    write_log(log_dir, "endpoint {} dbport {}".format(base_url, db_port))

    win, lbl = None, None
    try:
        win, lbl = make_progress_window()
    except Exception:
        pass

    def status(text):
        write_log(log_dir, text)
        if lbl is not None:
            try:
                lbl.config(text=text)
                win.update()
            except Exception:
                pass

    apache_state = service_state(SERVICE_APACHE)
    maria_state = service_state(SERVICE_MARIA)
    if apache_state is None:
        status("未检测到 Windows 服务")
        if win is not None:
            win.close()
        show_error(install_dir, [
            "未检测到 MOS 平台 Windows 服务。",
            "请先运行 MOS 平台安装程序。",
        ])
        return 11

    status("正在检查数据库服务…")
    if maria_state != "RUNNING":
        service_start(SERVICE_MARIA)

    status("正在检查网站服务…")
    apache_retries = 0
    if service_state(SERVICE_APACHE) != "RUNNING":
        if not service_start(SERVICE_APACHE):
            apache_retries += 1
        if apache_retries and not check_vc_redist():
            status("正在安装运行库组件…")
            install_vc_redist(install_dir)
            service_start(SERVICE_APACHE)

    status("正在等待平台就绪…")
    deadline = time.time() + TIMEOUT_SECONDS
    http_ready = False
    last_http = 0
    db_ready = False
    while time.time() < deadline:
        if not db_ready:
            db_ready = tcp_open("127.0.0.1", db_port)
        last_http = http_status(base_url + "/session/begin")
        if 200 <= last_http < 500:
            http_ready = True
            break
        if service_state(SERVICE_APACHE) == "STOPPED":
            service_start(SERVICE_APACHE)
        if service_state(SERVICE_MARIA) == "STOPPED":
            service_start(SERVICE_MARIA)
        pump_events(win)
        time.sleep(2)

    if win is not None:
        try:
            win.close()
        except Exception:
            pass

    if not http_ready:
        write_log(log_dir, "launch failed http={} db={}".format(last_http, db_ready))
        show_error(install_dir, [
            "网站服务状态：" + str(service_state(SERVICE_APACHE)),
            "数据库服务状态：" + str(service_state(SERVICE_MARIA)),
            "平台地址：" + base_url,
            "最近检查结果：HTTP {}".format(last_http),
        ])
        return 20

    plugin_url = base_url + "/plugins/mos-gov"
    plugin_code = http_status(plugin_url)
    open_url = base_url + "/session/begin"
    if 200 <= plugin_code < 500 and plugin_code != 404:
        open_url = plugin_url
    write_log(log_dir, "opening browser: " + open_url)
    try:
        os.startfile(open_url)
    except OSError:
        webbrowser.open(base_url)
    write_log(log_dir, "=== MOSLauncher exit 0 ===")
    return 0


def pump_events(win):
    if win is not None:
        try:
            win.update()
        except Exception:
            pass


if __name__ == "__main__":
    sys.exit(main())
