#define AppVersion "0.2.1"

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
OutputBaseFilename=MOS-GOV-V0.2.1-Setup
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

function InitializeSetup: Boolean;
var
  Choice: Integer;
begin
  ResetExistingData := False;

  if FileExists(ExpandConstant('{commonappdata}\MOS-GOV\config\install-state.json')) then
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
  end;

  Result := True;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  RuntimeParams: String;
begin
  if CurStep = ssPostInstall then
  begin
    RuntimeParams := '/c ""' + ExpandConstant('{app}\runtime\install-runtime.cmd') + '""';
    if ResetExistingData then
      RuntimeParams := '/c ""' + ExpandConstant('{app}\runtime\install-runtime.cmd') + '" /reset-data"';

    if not Exec(
      ExpandConstant('{cmd}'),
      RuntimeParams,
      ExpandConstant('{app}\runtime'),
      SW_HIDE,
      ewWaitUntilTerminated,
      RuntimeResultCode
    ) then
    begin
      SuppressibleMsgBox('MOS-GOV 运行环境启动失败，无法执行安装初始化脚本。', mbError, MB_OK, 0);
      Abort;
    end;

    if RuntimeResultCode <> 0 then
    begin
      SuppressibleMsgBox(
        'MOS-GOV 运行环境初始化失败。安装程序返回码：' + IntToStr(RuntimeResultCode) +
        '。请查看 C:\ProgramData\MOS-GOV\logs\installer.log。',
        mbError,
        MB_OK,
        0
      );
      Abort;
    end;
  end;
end;

[UninstallRun]
Filename: "{cmd}"; Parameters: "/c ""{app}\runtime\uninstall-runtime.cmd"""; Flags: runhidden waituntilterminated logoutput; RunOnceId: "RemoveMOSGovServices"; WorkingDir: "{app}\runtime"
