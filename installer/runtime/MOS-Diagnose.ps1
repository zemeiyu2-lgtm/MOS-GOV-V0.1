# MOS-Diagnose.ps1 - MOS 平台一键诊断
# 检查安装 / 服务 / 端口 / PHP / 数据库 / HTTP / 快捷方式，输出 PASS / WARN / FAIL 报告。
param(
    [string]$InstallDir = "",
    [string]$ReportPath = ""
)

$ErrorActionPreference = 'Continue'
$script:Lines = New-Object System.Collections.Generic.List[string]
$script:Fail = 0
$script:Warn = 0
$script:Pass = 0

function Resolve-InstallDir {
    if ($InstallDir -and (Test-Path $InstallDir)) { return $InstallDir }
    foreach ($key in 'HKLM:\SOFTWARE\MOS-GOV', 'HKLM:\SOFTWARE\WOW6432Node\MOS-GOV') {
        try {
            $p = (Get-ItemProperty -Path $key -ErrorAction Stop).InstallPath
            if ($p -and (Test-Path $p)) { return $p }
        } catch { }
    }
    $here = Split-Path -Parent $MyInvocation.MyCommand.Path
    if ($here) {
        $parent = Split-Path -Parent $here
        if (Test-Path (Join-Path $parent 'Apache24')) { return $parent }
    }
    return 'C:\Program Files\MOS-GOV'
}

$Root = Resolve-InstallDir
$LogDir = Join-Path $env:ProgramData 'MOS-GOV\logs'
if (-not $ReportPath) { $ReportPath = Join-Path ([Environment]::GetFolderPath('Desktop')) 'MOS-GOV-Diagnostics.txt' }

function Add-Result([string]$status, [string]$name, [string]$detail) {
    if ($status -eq 'PASS') { $script:Pass++ }
    elseif ($status -eq 'WARN') { $script:Warn++ }
    elseif ($status -eq 'FAIL') { $script:Fail++ }
    $script:Lines.Add(("[{0}] {1}{2}" -f $status, $name, $(if ($detail) { " - " + $detail } else { "" })))
}

function Section([string]$title) {
    $script:Lines.Add('')
    $script:Lines.Add(('== ' + $title + ' =='))
}

function Get-ServiceState([string]$name) {
    $s = Get-Service -Name $name -ErrorAction SilentlyContinue
    if (-not $s) { return $null }
    return $s.Status.ToString()
}

function Test-TcpPort([string]$h, [int]$p) {
    $c = New-Object Net.Sockets.TcpClient
    try {
        $r = $c.BeginConnect($h, $p, $null, $null)
        if ($r.AsyncWaitHandle.WaitOne(1500) -and $c.Connected) { return $true }
        return $false
    } catch { return $false } finally { $c.Close() }
}

function Get-HttpStatus([string]$url, [int]$Attempts = 3) {
    for ($attempt = 1; $attempt -le $Attempts; $attempt++) {
        $code = Get-HttpStatusOnce $url
        if ($code -ne 0) { return $code }
        Start-Sleep -Milliseconds 800
    }
    return 0
}

function Get-HttpStatusOnce([string]$url) {
    # 原生 socket 直接请求，绕过系统/企业代理（代理会导致本机探测误报 0）
    try {
        $u = [Uri]$url
        $client = New-Object System.Net.Sockets.TcpClient
        $iar = $client.BeginConnect($u.Host, $u.Port, $null, $null)
        if (-not $iar.AsyncWaitHandle.WaitOne(3000)) { $client.Close(); return 0 }
        $client.EndConnect($iar)
        $client.ReceiveTimeout = 3000
        $client.SendTimeout = 3000
        $stream = $client.GetStream()
        $req = "GET $($u.PathAndQuery) HTTP/1.1`r`nHost: $($u.Host):$($u.Port)`r`nUser-Agent: MOS-Diagnose/1.0`r`nConnection: close`r`n`r`n"
        $bytes = [Text.Encoding]::ASCII.GetBytes($req)
        $stream.Write($bytes, 0, $bytes.Length)
        $buffer = New-Object byte[] 1024
        $data = ''
        $deadline = (Get-Date).AddSeconds(3)
        while ((Get-Date) -lt $deadline -and $data -notmatch "\r\n") {
            $read = $stream.Read($buffer, 0, $buffer.Length)
            if ($read -le 0) { break }
            $data += [Text.Encoding]::ASCII.GetString($buffer, 0, $read)
        }
        $client.Close()
        if (-not $data) { return 0 }
        $line = $data.Split("`r`n")[0]
        if ($line -match 'HTTP/\d\.\d\s+(\d{3})') { return [int]$Matches[1] }
        return 0
    } catch { return 0 }
}

$script:Lines.Add(('MOS 平台诊断报告  ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')))
$script:Lines.Add(('安装目录：' + $Root))

# ---- 安装目录 ----
Section '安装与文件'
Add-Result 'INFO' '安装目录' $Root
foreach ($rel in 'Apache24\bin\httpd.exe', 'MariaDB\bin\mysqld.exe', 'PHP\php.exe', 'ChurchCRM\index.php', 'runtime\MOSLauncher.ps1') {
    $p = Join-Path $Root $rel
    if (Test-Path $p) { Add-Result 'PASS' ('文件 ' + $rel) '存在' }
    else { Add-Result 'FAIL' ('文件 ' + $rel) '缺失' }
}

# ---- ProgramData ----
Section '数据目录 (ProgramData)'
$pd = Join-Path $env:ProgramData 'MOS-GOV'
if (Test-Path $pd) {
    Add-Result 'PASS' '数据目录' $pd
    foreach ($rel in 'data', 'logs') {
        if (Test-Path (Join-Path $pd $rel)) { Add-Result 'PASS' ('目录 ' + $rel) '存在' }
        else { Add-Result 'WARN' ('目录 ' + $rel) '不存在' }
    }
} else {
    Add-Result 'FAIL' '数据目录' ('不存在：' + $pd)
}

# ---- 服务 ----
Section 'Windows 服务'
$svcApache = 'MOS-GOV-Apache'
$svcMaria = 'MOS-GOV-MariaDB'
$aState = Get-ServiceState $svcApache
$mState = Get-ServiceState $svcMaria
if ($aState) { Add-Result ($(if ($aState -eq 'Running') { 'PASS' } else { 'FAIL' })) ('服务 ' + $svcApache) $aState }
else { Add-Result 'FAIL' ('服务 ' + $svcApache) '未安装' }
if ($mState) { Add-Result ($(if ($mState -eq 'Running') { 'PASS' } else { 'FAIL' })) ('服务 ' + $svcMaria) $mState }
else { Add-Result 'FAIL' ('服务 ' + $svcMaria) '未安装' }

# ---- 端口 ----
Section '端口'
$httpPort = 8080
$conf = Join-Path $Root 'Apache24\conf\httpd.conf'
if (Test-Path $conf) {
    foreach ($line in Get-Content $conf -ErrorAction SilentlyContinue) {
        $t = $line.Trim()
        if ($t -match '^(#)?\s*Listen\s+(.+)$' -and -not $Matches[1]) {
            if ($Matches[2].Trim() -match '^(?:.+):(\d+)$') { $httpPort = [int]$Matches[1]; break }
            if ($Matches[2].Trim() -match '^(\d+)$') { $httpPort = [int]$Matches[1]; break }
        }
    }
    Add-Result 'INFO' 'Apache 监听端口' $httpPort
} else {
    Add-Result 'FAIL' 'Apache 配置' ('缺失：' + $conf)
}
$dbPort = 3306
$myini = Join-Path $pd 'data\my.ini'
if (Test-Path $myini) {
    $inM = $false
    foreach ($line in Get-Content $myini -ErrorAction SilentlyContinue) {
        $t = $line.Trim()
        if ($t -match '^\[(.+)\]$') { $inM = ($Matches[1] -ieq 'mysqld'); continue }
        if ($inM -and $t -match '^port\s*=\s*(\d+)') { $dbPort = [int]$Matches[1]; break }
    }
}
if (Test-TcpPort '127.0.0.1' $httpPort) { Add-Result 'PASS' ('HTTP 端口 ' + $httpPort) '可连接' }
else { Add-Result 'FAIL' ('HTTP 端口 ' + $httpPort) '无法连接' }
if (Test-TcpPort '127.0.0.1' $dbPort) { Add-Result 'PASS' ('MariaDB 端口 ' + $dbPort) '可连接' }
else { Add-Result 'FAIL' ('MariaDB 端口 ' + $dbPort) '无法连接' }

# ---- PHP ----
Section 'PHP'
$phpExe = Join-Path $Root 'PHP\php.exe'
if (Test-Path $phpExe) {
    $v = & $phpExe -v 2>$null | Select-Object -First 1
    if ($v) { Add-Result 'PASS' 'PHP 版本' $v } else { Add-Result 'FAIL' 'PHP 运行' '无法执行' }
    $out = & $phpExe -m 2>$null
    foreach ($ext in 'mysqli', 'mbstring', 'openssl', 'curl', 'gd', 'zip', 'intl', 'bcmath') {
        if ($out -contains $ext) { Add-Result 'PASS' ('PHP 扩展 ' + $ext) '已加载' }
        else { Add-Result 'WARN' ('PHP 扩展 ' + $ext) '未加载' }
    }
} else {
    Add-Result 'FAIL' 'PHP' 'php.exe 缺失'
}

# ---- HTTP / 平台页面 ----
Section 'HTTP 与平台页面'
$base = ('http://127.0.0.1:{0}' -f $httpPort)
$c1 = Get-HttpStatus ($base + '/session/begin')
if ($c1 -ge 200 -and $c1 -lt 500) { Add-Result 'PASS' '登录页 /session/begin' ('HTTP ' + $c1) }
else { Add-Result 'FAIL' '登录页 /session/begin' ('HTTP ' + $c1) }
$c2 = Get-HttpStatus ($base + '/plugins/mos-gov')
if ($c2 -ge 200 -and $c2 -lt 500 -and $c2 -ne 404) { Add-Result 'PASS' 'MOS-GOV 插件入口 /plugins/mos-gov' ('HTTP ' + $c2) }
else { Add-Result 'WARN' 'MOS-GOV 插件入口 /plugins/mos-gov' ('HTTP ' + $c2) }

# ---- 快捷方式 ----
Section '桌面快捷方式'
$desk = [Environment]::GetFolderPath('CommonDesktopDirectory')
$lnk = Join-Path $desk 'MOS 平台.lnk'
if (Test-Path $lnk) {
    $sh = New-Object -ComObject WScript.Shell
    $sc = $sh.CreateShortcut($lnk)
    Add-Result 'PASS' '桌面快捷方式 MOS 平台' ('Target=' + $sc.TargetPath)
} else {
    Add-Result 'WARN' '桌面快捷方式 MOS 平台' '不存在'
}

# ---- 最近错误 ----
Section '最近日志'
$phpLog = Join-Path $LogDir 'php-error.log'
if (Test-Path $phpLog) {
    $tail = (Get-Content $phpLog -Encoding Default -Tail 5 -ErrorAction SilentlyContinue)
    foreach ($l in $tail) { $script:Lines.Add('  ' + $l) }
} else {
    $script:Lines.Add('  无 PHP 错误日志')
}

# ---- 汇总 ----
$script:Lines.Add('')
$script:Lines.Add(('== 汇总 =='))
$script:Lines.Add(('PASS: ' + $script:Pass + '   WARN: ' + $script:Warn + '   FAIL: ' + $script:Fail))
if ($script:Fail -gt 0) { $script:Lines.Add('结论：存在问题，请把本报告发给技术支持。') }
elseif ($script:Warn -gt 0) { $script:Lines.Add('结论：基本正常（存在可改进项）。') }
else { $script:Lines.Add('结论：MOS 平台安装状态正常。') }

$script:Lines | Out-File -FilePath $ReportPath -Encoding utf8
Write-Host ''
Write-Host ('诊断完成，报告已保存：' + $ReportPath)
Write-Host ('PASS: ' + $script:Pass + '  WARN: ' + $script:Warn + '  FAIL: ' + $script:Fail)
try { Start-Process notepad.exe $ReportPath } catch { }
exit $(if ($script:Fail -gt 0) { 1 } else { 0 })
