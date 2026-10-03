# MOSLauncher.ps1 - MOS 平台启动器
# 目标：双击图标 -> 自动检查/启动服务 -> 等待健康 -> 打开浏览器进入 MOS 平台。
# 本脚本不得显示任何控制台窗口（由 MOSLauncher.vbs / MOSLauncher.exe 隐藏承载）。

param(
    [string]$InstallDir = "",
    [string]$RuntimeDir = "",
    [int]$TimeoutSeconds = 60
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

# ---------------------------------------------------------------- 定位安装目录
function Resolve-InstallDir {
    if ($InstallDir -and (Test-Path $InstallDir)) { return $InstallDir }
    # 注册表（安装器写入）
    foreach ($key in 'HKLM:\SOFTWARE\MOS-GOV', 'HKLM:\SOFTWARE\WOW6432Node\MOS-GOV') {
        try {
            $p = (Get-ItemProperty -Path $key -ErrorAction Stop).InstallPath
            if ($p -and (Test-Path $p)) { return $p }
        } catch { }
    }
    if ($RuntimeDir) {
        $parent = Split-Path -Parent $RuntimeDir
        if (Test-Path (Join-Path $parent 'Apache24')) { return $parent }
    }
    $here = Split-Path -Parent $MyInvocation.MyCommand.Path
    if ($here) {
        $parent = Split-Path -Parent $here
        if (Test-Path (Join-Path $parent 'Apache24')) { return $parent }
    }
    return 'C:\Program Files\MOS-GOV'
}

$script:InstallRoot = Resolve-InstallDir
$script:RuntimeRoot = Join-Path $InstallRoot 'runtime'
$script:HttpdConf   = Join-Path $InstallRoot 'Apache24\conf\httpd.conf'
$script:MyIni       = Join-Path $env:ProgramData 'MOS-GOV\data\my.ini'
$script:LogDir      = Join-Path $env:ProgramData 'MOS-GOV\logs'
$script:LogFile     = Join-Path $LogDir 'launcher.log'

$script:LogOk = $true
try {
    if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Force -Path $LogDir -ErrorAction Stop | Out-Null }
} catch {
    # ProgramData 不可写时退回用户临时目录，绝不因此中断启动
    try {
        $LogDir = Join-Path $env:TEMP 'MOS-GOV-logs'
        if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Force -Path $LogDir -ErrorAction Stop | Out-Null }
    } catch { $script:LogOk = $false }
}
$script:LogFile = Join-Path $LogDir 'launcher.log'

function Write-Log([string]$msg) {
    try { Add-Content -Path $LogFile -Value ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg) -ErrorAction SilentlyContinue } catch { }
}

# ---------------------------------------------------------------- 读取真实端口
function Get-HttpEndpoint {
    $host_ = '127.0.0.1'
    $port = $null
    try {
        if (Test-Path $HttpdConf) {
            foreach ($line in Get-Content $HttpdConf -ErrorAction Stop) {
                $t = $line.Trim()
                if ($t -match '^(#)?\s*Listen\s+(.+)$' -and -not $Matches[1]) {
                    $spec = $Matches[2].Trim()
                    # Listen 127.0.0.1:8080 / Listen 8080 / Listen 0.0.0.0:8080
                    if ($spec -match '^(.+):(\d+)$') { $host_ = $Matches[1]; $port = [int]$Matches[2] }
                    elseif ($spec -match '^(\d+)$') { $port = [int]$Matches[1]; $host_ = '0.0.0.0' }
                    if ($port) { break }
                }
            }
        }
    } catch { }
    if (-not $port) { $port = 8080; $host_ = '127.0.0.1' }
    if ($host_ -eq '0.0.0.0' -or $host_ -eq '*' -or $host_ -eq '_default_') { $host_ = '127.0.0.1' }
    return @{ Host = $host_; Port = $port }
}

function Get-MariaDbPort {
    try {
        if (Test-Path $MyIni) {
            $inMysqld = $false
            foreach ($line in Get-Content $MyIni -ErrorAction Stop) {
                $t = $line.Trim()
                if ($t -match '^\[(.+)\]$') { $inMysqld = ($Matches[1] -ieq 'mysqld'); continue }
                if ($inMysqld -and $t -match '^port\s*=\s*(\d+)') { return [int]$Matches[1] }
            }
        }
    } catch { }
    return 3306
}

# ---------------------------------------------------------------- 服务
function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-ServiceState([string]$name) {
    $s = Get-Service -Name $name -ErrorAction SilentlyContinue
    if (-not $s) { return $null }
    return $s.Status.ToString()
}

function Start-MosService([string]$name) {
    Write-Log "starting service $name"
    try {
        Start-Service -Name $name -ErrorAction Stop
        return $true
    } catch {
        Write-Log ("direct start failed: " + $_.Exception.Message)
    }
    # 权限不足 -> 以管理员身份用 net start 启动
    try {
        $p = Start-Process -FilePath 'net.exe' -ArgumentList @('start', $name) -Verb RunAs -Wait -PassThru -ErrorAction Stop
        Write-Log ("elevated net start exit " + $p.ExitCode)
        return ($p.ExitCode -eq 0)
    } catch {
        Write-Log ("elevated start failed: " + $_.Exception.Message)
        return $false
    }
}

# ---------------------------------------------------------------- HTTP / TCP 健康检查
function Test-TcpPort([string]$h, [int]$p) {
    $c = New-Object Net.Sockets.TcpClient
    try {
        $r = $c.BeginConnect($h, $p, $null, $null)
        $ok = $r.AsyncWaitHandle.WaitOne(1500)
        if ($ok -and $c.Connected) { return $true }
        return $false
    } catch { return $false }
    finally { $c.Close() }
}

function Get-HttpStatus([string]$url) {
    try {
        $req = [Net.HttpWebRequest]::Create($url)
        $req.Method = 'GET'
        $req.Timeout = 2500
        $req.ReadWriteTimeout = 2500
        $req.AllowAutoRedirect = $false
        $req.UserAgent = 'MOSLauncher/1.0'
        $resp = $req.GetResponse()
        $code = [int]$resp.StatusCode
        $resp.Close()
        return $code
    } catch [Net.WebException] {
        if ($_.Exception.Response) {
            return [int]$_.Exception.Response.StatusCode
        }
        return 0
    } catch { return 0 }
}

# ---------------------------------------------------------------- 进度窗
$script:ProgressForm = $null
function Show-ProgressWindow {
    $f = New-Object System.Windows.Forms.Form
    $f.Text = 'MOS 平台'
    $f.Size = New-Object System.Drawing.Size(360, 130)
    $f.StartPosition = 'CenterScreen'
    $f.FormBorderStyle = 'None'
    $f.TopMost = $true
    $f.BackColor = [System.Drawing.Color]::FromArgb(15, 60, 140)
    $label = New-Object System.Windows.Forms.Label
    $label.Text = '正在启动 MOS 平台…'
    $label.ForeColor = [System.Drawing.Color]::White
    $label.Font = New-Object System.Drawing.Font('Microsoft YaHei UI', 11)
    $label.Dock = 'Fill'
    $label.TextAlign = 'MiddleCenter'
    $f.Controls.Add($label)
    $f.Show()
    $f.Refresh()
    $script:ProgressForm = $f
    $script:ProgressLabel = $label
}
function Update-Progress([string]$text) {
    try {
        if ($script:ProgressLabel) {
            $script:ProgressLabel.Text = $text
            [System.Windows.Forms.Application]::DoEvents()
        }
    } catch { }
}
function Close-ProgressWindow {
    try { if ($script:ProgressForm) { $script:ProgressForm.Close() } } catch { }
    $script:ProgressForm = $null
}

# ---------------------------------------------------------------- 错误窗
function Show-ErrorWindow([string[]]$lines) {
    $text = ($lines -join "`r`n")
    $msg = 'MOS平台暂时无法启动。' + "`r`n`r`n" + $text
    $result = [System.Windows.Forms.MessageBox]::Show(
        $msg, 'MOS 平台',
        [System.Windows.Forms.MessageBoxButtons]::OKCancel,
        [System.Windows.Forms.MessageBoxIcon]::Error)
    if ($result -eq [System.Windows.Forms.DialogResult]::OK) {
        # 打开诊断信息
        $diag = Join-Path $RuntimeRoot 'MOS-Diagnose.ps1'
        if (Test-Path $diag) {
            Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File', ('"{0}"' -f $diag)) -WindowStyle Normal
        }
    }
}

# ---------------------------------------------------------------- VC++ 运行库
function Test-VcRedistInstalled {
    foreach ($key in 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64',
                     'HKLM:\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\x64') {
        try {
            $v = (Get-ItemProperty -Path $key -ErrorAction Stop).Installed
            if ($v -eq 1) { return $true }
        } catch { }
    }
    return $false
}

function Install-VcRedist {
    $vc = Join-Path $InstallRoot 'prereqs\vc_redist.x64.exe'
    if (-not (Test-Path $vc)) { return $false }
    try {
        $p = Start-Process -FilePath $vc -ArgumentList @('/install','/quiet','/norestart') -Verb RunAs -Wait -PassThru -ErrorAction Stop
        Write-Log ("vc_redist exit " + $p.ExitCode)
        return ($p.ExitCode -eq 0)
    } catch {
        Write-Log ("vc_redist failed: " + $_.Exception.Message)
        return $false
    }
}

# ---------------------------------------------------------------- 主流程
function Invoke-MosLaunch {
    Write-Log "=== MOSLauncher start ==="
    Write-Log ("install root: " + $InstallRoot)

    if (-not (Test-Path (Join-Path $InstallRoot 'Apache24\bin\httpd.exe'))) {
        Show-ErrorWindow @('未检测到 MOS 平台安装目录。', '安装目录：' + $InstallRoot, '请先运行 MOS 平台安装程序。')
        return 10
    }

    $ep = Get-HttpEndpoint
    $dbPort = Get-MariaDbPort
    $script:BaseUrl = ('http://{0}:{1}' -f $ep.Host, $ep.Port)
    Write-Log ("http endpoint: " + $BaseUrl + "  db port: " + $dbPort)

    Show-ProgressWindow

    $svcApache = 'MOS-GOV-Apache'
    $svcMaria  = 'MOS-GOV-MariaDB'

    $apacheState = Get-ServiceState $svcApache
    $mariaState  = Get-ServiceState $svcMaria
    if (-not $apacheState) {
        Close-ProgressWindow
        Show-ErrorWindow @('未检测到 MOS 平台 Windows 服务。', '请先运行 MOS 平台安装程序。')
        return 11
    }
    Update-Progress '正在检查数据库服务…'

    if ($mariaState -ne 'Running') {
        [void](Start-MosService $svcMaria)
    }

    Update-Progress '正在检查网站服务…'
    if ($apacheState -ne 'Running') {
        $ok = Start-MosService $svcApache
        if (-not $ok -and -not (Test-VcRedistInstalled)) {
            Update-Progress '正在安装运行库组件…'
            [void](Install-VcRedist)
            $ok = Start-MosService $svcApache
        }
    }

    # ---- 等待健康 ----
    Update-Progress '正在等待平台就绪…'
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $httpReady = $false
    $dbReady = $false
    $lastHttp = 0
    while ((Get-Date) -lt $deadline) {
        if (-not $dbReady) { $dbReady = Test-TcpPort '127.0.0.1' $dbPort }
        $lastHttp = Get-HttpStatus ($BaseUrl + '/session/begin')
        if (($lastHttp -ge 200 -and $lastHttp -lt 500)) { $httpReady = $true; break }
        # 服务中途退出则再拉起一次
        if ((Get-ServiceState $svcApache) -eq 'Stopped') { [void](Start-MosService $svcApache) }
        if ((Get-ServiceState $svcMaria) -eq 'Stopped') { [void](Start-MosService $svcMaria) }
        Start-Sleep -Seconds 2
    }

    Close-ProgressWindow

    if (-not $httpReady) {
        Write-Log ("launch failed: http=" + $lastHttp + " db=" + $dbReady)
        Show-ErrorWindow @(
            'MOS平台暂时无法启动。',
            ('网站服务状态：' + (Get-ServiceState $svcApache)),
            ('数据库服务状态：' + (Get-ServiceState $svcMaria)),
            ('平台地址：' + $BaseUrl),
            ('最近检查结果：HTTP ' + $lastHttp)
        )
        return 20
    }

    # ---- 打开平台 ----
    $pluginUrl = $BaseUrl + '/plugins/mos-gov'
    $pluginCode = Get-HttpStatus $pluginUrl
    $openUrl = $BaseUrl + '/session/begin'
    if ($pluginCode -ge 200 -and $pluginCode -lt 500 -and $pluginCode -ne 404) { $openUrl = $pluginUrl }
    Write-Log ("opening browser: " + $openUrl)
    try { Start-Process $openUrl } catch {
        try { Start-Process ('{0}' -f $BaseUrl) } catch { }
    }
    return 0
}

$exitCode = Invoke-MosLaunch
Write-Log ("=== MOSLauncher exit " + $exitCode + " ===")
exit $exitCode
