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

[Code]
var
  RuntimeResultCode: Integer;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
  begin
    if not Exec(
      ExpandConstant('{cmd}'),
      '/c ""' + ExpandConstant('{app}\runtime\install-runtime.cmd') + '""',
      ExpandConstant('{app}\runtime'),
      SW_HIDE,
      ewWaitUntilTerminated,
      RuntimeResultCode
    ) then
    begin
      SuppressibleMsgBox('MOS-GOV 运行环境启动失败，无法执行安装初始化脚本。', mbError, MB_OK);
      Abort;
    end;

    if RuntimeResultCode <> 0 then
    begin
      SuppressibleMsgBox(
        'MOS-GOV 运行环境初始化失败。安装程序返回码：' + IntToStr(RuntimeResultCode) +
        '。请查看 C:\ProgramData\MOS-GOV\logs\installer.log。',
        mbError,
        MB_OK
      );
      Abort;
    end;
  end;
end;

[UninstallRun]
Filename: "{cmd}"; Parameters: "/c ""{app}\runtime\uninstall-runtime.cmd"""; Flags: runhidden waituntilterminated logoutput; RunOnceId: "RemoveMOSGovServices"; WorkingDir: "{app}\runtime"
