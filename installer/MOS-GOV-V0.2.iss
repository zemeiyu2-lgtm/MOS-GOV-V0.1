#define AppVersion "0.2.2"

[Setup]
AppId={{5F78C9D9-08D7-43A6-9C69-2D1D0BAF2F65}
AppName=MOS 平台
AppVersion={#AppVersion}
AppVerName=MOS 平台 (MOS-GOV) {#AppVersion}
AppPublisher=MOS
AppPublisherURL=https://github.com/zemeiyu2-lgtm/MOS-GOV-V0.1
DefaultDirName={autopf}\MOS-GOV
DefaultGroupName=MOS 平台
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputBaseFilename=MOS-GOV-V0.2.2-Setup
OutputDir={{OUTPUT_DIR}}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
SetupIconFile={{PAYLOAD_ROOT}}\runtime\MOS.ico
Uninstallable=yes
UninstallDisplayName=MOS 平台 (MOS-GOV 教会治理)
UninstallDisplayIcon={app}\runtime\MOS.ico
UninstallFilesDir={app}\uninstall
CloseApplications=yes
RestartApplications=no
CreateAppDir=yes
MinVersion=10.0.17763
PrivilegesRequiredOverridesAllowed=dialog

[Files]
Source: "{{PAYLOAD_ROOT}}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{commondesktop}\MOS 平台"; Filename: "{app}\runtime\MOSLauncher.exe"; WorkingDir: "{app}"; IconFilename: "{app}\runtime\MOS.ico"; Comment: "启动 MOS 平台"; IconIndex: 0
Name: "{group}\MOS 平台"; Filename: "{app}\runtime\MOSLauncher.exe"; WorkingDir: "{app}"; IconFilename: "{app}\runtime\MOS.ico"; Comment: "启动 MOS 平台"; IconIndex: 0
Name: "{group}\MOS 诊断"; Filename: "{app}\runtime\MOS-Diagnose.cmd"; WorkingDir: "{app}\runtime"; IconFilename: "{app}\runtime\MOS.ico"; Comment: "检查 MOS 平台安装状态"; IconIndex: 0
Name: "{group}\新建教会（清除旧数据）"; Filename: "{app}\runtime\reset-church.cmd"; WorkingDir: "{app}\runtime"

[Registry]
Root: HKLM; Subkey: "SOFTWARE\MOS-GOV"; ValueType: string; ValueName: "InstallPath"; ValueData: "{app}"; Flags: uninsdeletekey
Root: HKLM; Subkey: "SOFTWARE\MOS-GOV"; ValueType: string; ValueName: "Version"; ValueData: "{#AppVersion}"; Flags: uninsdeletekey

[Run]
Filename: "{app}\runtime\MOSLauncher.exe"; Description: "立即打开 MOS 平台"; WorkingDir: "{app}"; Flags: postinstall nowait skipifsilent runasoriginaluser

[Code]
var
  RuntimeResultCode: Integer;
  ResetExistingData: Boolean;

function ServiceExists(const Name: String): Boolean;
begin
  { 通过注册表检查服务是否存在（SYSTEM\CurrentControlSet\Services\<Name>）。
    不依赖外部进程（sc.exe/cmd），在各种受限环境下都可靠。 }
  Result := RegKeyExists(HKLM, 'SYSTEM\CurrentControlSet\Services\' + Name);
end;

procedure StopMosService(const Name: String);
var
  ResultCode: Integer;
begin
  if RegKeyExists(HKLM, 'SYSTEM\CurrentControlSet\Services\' + Name) then
  begin
    Exec(ExpandConstant('{sys}\net.exe'), 'stop "' + Name + '"',
      '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  end;
end;

function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  Result := '';
  { 重复安装/升级前先停止服务，避免 httpd.exe / mariadbd.exe 被占用导致文件替换失败。 }
  StopMosService('MOS-GOV-Apache');
  StopMosService('MOS-GOV-MariaDB');
  Sleep(2000);
end;

function InitializeSetup: Boolean;
var
  HasState, HasServices: Boolean;
  Choice: Integer;
begin
  ResetExistingData := False;

  HasState := FileExists(ExpandConstant('{commonappdata}\MOS-GOV\config\install-state.json'));
  HasServices :=
    ServiceExists('MOS-GOV-Apache') and ServiceExists('MOS-GOV-MariaDB');

  if HasState then
  begin
    if WizardSilent then
    begin
      { 静默安装：服务在 -> 升级保留数据；服务缺失 -> 重建环境（旧数据自动备份）}
      ResetExistingData := not HasServices;
    end
    else if HasServices then
    begin
      Choice := MsgBox(
        '检测到本机已经存在 MOS 平台（MOS-GOV）数据。' + #13#10#13#10 +
        '选择【是】：新建教会 —— 保留旧数据备份，清除旧数据库，建立一个全新的教会环境。' + #13#10 +
        '选择【否】：升级程序 —— 保留现有教会数据，只更新程序文件（推荐）。' + #13#10 +
        '选择【取消】：终止本次安装。',
        mbConfirmation,
        MB_YESNOCANCEL
      );
      case Choice of
        IDYES:
          ResetExistingData := True;
        IDCANCEL:
          begin
            MsgBox('已取消安装，现有教会数据没有改变。', mbInformation, MB_OK);
            Result := False;
            exit;
          end;
      end;
      ; { IDNO -> 升级安装，保留数据 }
    end
    else
    begin
      { 有数据但服务缺失（例如之前运行过卸载）：运行环境必须重建，
        旧数据库会先自动备份到 C:\ProgramData\MOS-GOV\backups }
      if MsgBox(
        '检测到 MOS 平台旧数据，但本机的 MOS 平台服务已不存在（可能被卸载）。' + #13#10#13#10 +
        '需要重建运行环境才能继续。' + #13#10#13#10 +
        '选择【是】：自动备份旧数据后重建全新的运行环境。' + #13#10 +
        '选择【否】：终止本次安装，现有数据保持不变。',
        mbConfirmation,
        MB_YESNO
      ) = IDNO then
      begin
        MsgBox('已取消安装，现有教会数据没有改变。', mbInformation, MB_OK);
        Result := False;
        exit;
      end;
      ResetExistingData := True;
    end;
  end;

  Result := True;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  RuntimeScript, RuntimeParams, BootstrapHint: String;
begin
  if CurStep = ssPostInstall then
  begin
    RuntimeScript := ExpandConstant('{app}\runtime\install-runtime.cmd');
    BootstrapHint := ExpandConstant('{tmp}\MOS-GOV-runtime-bootstrap.log');

    Log('MOS-GOV runtime bootstrap starting: ' + RuntimeScript);
    Log('ResetExistingData=' + IntToStr(Integer(ResetExistingData)));

    if not FileExists(RuntimeScript) then
    begin
      Log('FATAL: runtime bootstrap script missing: ' + RuntimeScript);
      SuppressibleMsgBox(
        'MOS-GOV 运行环境初始化脚本不存在：' + #13#10 + RuntimeScript +
        #13#10#13#10 + '安装包文件不完整，安装已停止。',
        mbError, MB_OK, 0
      );
      Abort;
    end;

    { Inno Setup 的 Exec 原生支持直接执行 .cmd/.bat。
      旧版本通过 cmd.exe /c 再套一层引号，在部分真实 Windows 环境下只得到
      exit code 1，且脚本本身甚至没有机会创建 installer.log。
      直接 Exec .cmd 可消除这一层命令行解析差异。 }
    RuntimeParams := '';
    if ResetExistingData then
      RuntimeParams := '/reset-data';

    if not Exec(
      RuntimeScript,
      RuntimeParams,
      ExpandConstant('{app}\runtime'),
      SW_HIDE,
      ewWaitUntilTerminated,
      RuntimeResultCode
    ) then
    begin
      Log('FATAL: could not execute runtime bootstrap. ErrorCode=' + IntToStr(RuntimeResultCode) +
        ' Message=' + SysErrorMessage(RuntimeResultCode));
      SuppressibleMsgBox(
        'MOS-GOV 运行环境初始化脚本无法执行。' + #13#10#13#10 +
        '错误：' + SysErrorMessage(RuntimeResultCode) + #13#10#13#10 +
        '诊断文件（如果已创建）：' + BootstrapHint,
        mbError, MB_OK, 0
      );
      Abort;
    end;

    Log('Runtime bootstrap exit code=' + IntToStr(RuntimeResultCode));

    if RuntimeResultCode <> 0 then
    begin
      Log('FATAL: runtime bootstrap failed with exit code ' + IntToStr(RuntimeResultCode));
      SuppressibleMsgBox(
        'MOS-GOV 运行环境初始化失败。安装程序返回码：' + IntToStr(RuntimeResultCode) +
        '.' + #13#10#13#10 +
        '请查看以下诊断文件：' + #13#10 +
        '%TEMP%\MOS-GOV-runtime-bootstrap.log' + #13#10 +
        'C:\ProgramData\MOS-GOV\logs\bootstrap.log' + #13#10 +
        'C:\ProgramData\MOS-GOV\logs\installer.log',
        mbError, MB_OK, 0
      );
      Abort;
    end;
  end;
end;

[UninstallRun]
Filename: "{cmd}"; Parameters: "/c ""{app}\runtime\uninstall-runtime.cmd"""; Flags: runhidden waituntilterminated logoutput; RunOnceId: "RemoveMOSGovServices"; WorkingDir: "{app}\runtime"
