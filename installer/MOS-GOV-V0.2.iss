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

[Languages]
Name: "chinesesimplified"; MessagesFile: "compiler:Languages\ChineseSimplified.isl"

[Files]
Source: "{{PAYLOAD_ROOT}}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\MOS-GOV 教会治理平台"; Filename: "{app}\runtime\open-mosgov.cmd"; WorkingDir: "{app}"
Name: "{commondesktop}\MOS-GOV 教会治理平台"; Filename: "{app}\runtime\open-mosgov.cmd"; WorkingDir: "{app}"

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\runtime\install-runtime.ps1"""; Flags: runhidden waituntilterminated; StatusMsg: "正在配置 MOS-GOV 本地运行环境……"

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\runtime\uninstall-runtime.ps1"""; Flags: runhidden waituntilterminated
