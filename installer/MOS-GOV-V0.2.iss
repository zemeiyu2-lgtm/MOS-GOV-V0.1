#define AppVersion "0.2.0"

[Setup]
AppId={{5F78C9D9-08D7-43A6-9C69-2D1D0BAF2F65}
AppName=MOS-GOV 教会治理平台
AppVersion={#AppVersion}
AppPublisher=MOS
AppPublisherURL=https://github.com/zemeiyu2-lgtm/MOS-GOV-V0.1
DefaultDirName={autopf}\MOS-GOV
DefaultGroupName=MOS-GOV 教会治理平台
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputBaseFilename=MOS-GOV-V0.2-Setup
OutputDir={{OUTPUT_DIR}}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
Uninstallable=yes
UninstallDisplayName=MOS-GOV 教会治理平台
UninstallFilesDir={app}\uninstall
CloseApplications=yes
RestartApplications=no
CreateAppDir=yes
MinVersion=10.0.17763
PrivilegesRequiredOverridesAllowed=dialog

[Files]
Source: "{{PAYLOAD_ROOT}}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\MOS-GOV 教会治理平台"; Filename: "{app}\runtime\open-mosgov.cmd"; WorkingDir: "{app}"
Name: "{commondesktop}\MOS-GOV 教会治理平台"; Filename: "{app}\runtime\open-mosgov.cmd"; WorkingDir: "{app}"
Name: "{group}\新建教会（清除旧数据）"; Filename: "{app}\runtime\reset-church.cmd"; WorkingDir: "{app}\runtime"
Name: "{commondesktop}\MOS-GOV 新建教会"; Filename: "{app}\runtime\reset-church.cmd"; WorkingDir: "{app}\runtime"

[Code]
var
  RuntimeResultCode: Integer;
  ResetExistingData: Boolean;

function InitializeSetup: Boolean;
begin
  ResetExistingData := False;

  if FileExists(ExpandConstant('{commonappdata}\MOS-GOV\config\install-state.json')) then
  begin
    if MsgBox(
      '检测到本机已经存在 MOS-GOV 教会数据。' + #13#10#13#10 +
      '选择“是”：保留旧数据备份，并清除旧数据库，建立一个全新的教会环境。' + #13#10 +
      '选择“否”：取消本次安装，避免误覆盖现有教会数据。',
      mbConfirmation,
      MB_YESNO
    ) = IDYES then
      ResetExistingData := True
    else
    begin
      MsgBox('已取消安装，现有教会数据没有改变。', mbInformation, MB_OK);
      Result := False;
      exit;
    end;
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
