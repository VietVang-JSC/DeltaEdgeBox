; DeltaPOS Edge Box - Inno Setup Installer
; Build with: ISCC.exe installer.iss

#define MyAppName "DeltaPOS Edge Box"
#define MyAppVersion GetEnv('APP_VERSION')
#define MyAppPublisher "VietVang Software"
#define MyAppURL "https://deltapos.cloud"
#define MyAppSupportURL "https://deltapos.cloud/support"

#if MyAppVersion == ""
  #define MyAppVersion "1.0.0"
#endif

[Setup]
AppId={{B8F7E3A1-2C4D-5F6E-8A9B-0C1D2E3F4A5B}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppSupportURL}
DefaultDirName=C:\DeltaPOS
DefaultGroupName=DeltaPOS Edge Box
OutputDir=dist
OutputBaseFilename=DeltaPOS-EdgeBox-Setup-v{#MyAppVersion}
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
WizardResizable=no
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=no
DisableReadyPage=no
UninstallDisplayName={#MyAppName}

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"
Name: "vietnamese"; MessagesFile: "compiler:Languages\Vietnamese.isl"

[Messages]
vietnamese.WelcomeLabel2=Trình cài đặt sẽ cài [name] vào máy tính của bạn.%n%nBạn cần quyền Administrator để cài đặt.
vietnamese.ReadyLabel1=Chuẩn bị cài đặt
vietnamese.ReadyLabel2a=Chương trình sẽ cài đặt [name] với các thiết lập sau:
vietnamese.FinishedLabelNoRun=Cài đặt hoàn tất.%n%nMở http://localhost:8000/edge-manager để quản lý Edge Box.
vietnamese.ExitSetupMessage=Bạn có chắc muốn thoát? Quá trình cài đặt chưa hoàn tất.

[Types]
Name: "full"; Description: "Full installation"
Name: "custom"; Description: "Custom installation"; Flags: iscustom

[Components]
Name: "app"; Description: "Edge Box Application"; Types: full custom; Flags: fixed
Name: "php"; Description: "PHP 8.3 Runtime"; Types: full custom; Flags: fixed
Name: "nssm"; Description: "Windows Service Manager (NSSM)"; Types: full custom; Flags: fixed

[Files]
; Application files
Source: "build\app\*"; DestDir: "{app}\edge-box"; Flags: ignoreversion recursesubdirs createallsubdirs; Components: app
; PHAR archive (contains obfuscated source code)
Source: "build\edge-box.phar"; DestDir: "{app}\edge-box"; Flags: ignoreversion; Components: app
; PHP runtime
Source: "build\php\*"; DestDir: "{app}\php"; Flags: ignoreversion recursesubdirs createallsubdirs; Components: php
; NSSM
Source: "build\nssm\nssm.exe"; DestDir: "{app}\nssm"; Flags: ignoreversion; Components: nssm
; Custom php.ini
Source: "scripts\php.ini"; DestDir: "{app}\php"; DestName: "php.ini"; Flags: ignoreversion
; SSL certificates
Source: "scripts\cacert.pem"; DestDir: "{app}\php"; Flags: ignoreversion
; Management shortcut URL
Source: "scripts\edge-manager.url"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{commondesktop}\DeltaPOS Edge Manager"; Filename: "{app}\edge-manager.url"; WorkingDir: "{app}"
Name: "{group}\Edge Manager"; Filename: "{app}\edge-manager.url"; WorkingDir: "{app}"
Name: "{group}\Start Services"; Filename: "{app}\nssm\nssm.exe"; Parameters: "start EdgeBoxWeb"; WorkingDir: "{app}\edge-box"
Name: "{group}\Stop Services"; Filename: "{app}\nssm\nssm.exe"; Parameters: "stop EdgeBoxWeb"; WorkingDir: "{app}\edge-box"
Name: "{group}\Restart Services"; Filename: "{app}\nssm\nssm.exe"; Parameters: "restart EdgeBoxWeb"; WorkingDir: "{app}\edge-box"
Name: "{group}\View Logs"; Filename: "{app}\edge-box\storage\logs"
Name: "{group}\Uninstall {#MyAppName}"; Filename: "{uninstallexe}"

[Run]
; Install services + setup
Filename: "{app}\scripts\post-install.bat"; Parameters: """{app}"""; Flags: runascurrentuser runhidden waituntilterminated
; Start services after install
Filename: "{app}\nssm\nssm.exe"; Parameters: "start EdgeBoxWeb"; Flags: runascurrentuser runhidden nowait
Filename: "{app}\nssm\nssm.exe"; Parameters: "start EdgeBoxPrint"; Flags: runascurrentuser runhidden nowait
Filename: "{app}\nssm\nssm.exe"; Parameters: "start EdgeBoxSchedule"; Flags: runascurrentuser runhidden nowait
; Open browser after install
Filename: "{win}\explorer.exe"; Parameters: "http://localhost:8000/edge-manager"; Flags: runascurrentuser nowait; Description: "Open Edge Manager"

[UninstallRun]
Filename: "{app}\nssm\nssm.exe"; Parameters: "stop EdgeBoxWeb"; Flags: runascurrentuser runhidden
Filename: "{app}\nssm\nssm.exe"; Parameters: "stop EdgeBoxPrint"; Flags: runascurrentuser runhidden
Filename: "{app}\nssm\nssm.exe"; Parameters: "stop EdgeBoxSchedule"; Flags: runascurrentuser runhidden
; Remove services
Filename: "{app}\nssm\nssm.exe"; Parameters: "remove EdgeBoxWeb confirm"; Flags: runascurrentuser runhidden
Filename: "{app}\nssm\nssm.exe"; Parameters: "remove EdgeBoxPrint confirm"; Flags: runascurrentuser runhidden
Filename: "{app}\nssm\nssm.exe"; Parameters: "remove EdgeBoxSchedule confirm"; Flags: runascurrentuser runhidden
; Remove firewall rule
Filename: "netsh.exe"; Parameters: "advfirewall firewall delete rule name=""DeltaPOS Edge Box (HTTP)"""; Flags: runascurrentuser runhidden

[UninstallDelete]
Type: filesandordirs; Name: "{app}\edge-box\storage\logs"
Type: filesandordirs; Name: "{app}\edge-box\storage\framework\cache"
Type: filesandordirs; Name: "{app}\edge-box\storage\framework\sessions"
Type: filesandordirs; Name: "{app}\edge-box\storage\framework\views"

[Code]

var
  ConfigPage: TInputQueryWizardPage;
  SyncIntervalCombo: TComboBox;
  LicenseKeyEdit: TEdit;
  GenerateKeyBtn: TButton;

procedure GenerateKeyClick(Sender: TObject);
var
  i: Integer;
  Key: string;
  Chars: string;
begin
  Chars := 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  Key := '';
  for i := 1 to 32 do
  begin
    Key := Key + Chars[GetRandom(Length(Chars)) + 1];
  end;
  ConfigPage.Values[2] := Key;
  LicenseKeyEdit.Text := Key;
end;

procedure InitializeWizard;
var
  DescLabel: TLabel;
  SyncLabel: TLabel;
  LicenseLabel: TLabel;
begin
  ConfigPage := CreateInputQueryPage(wpSelectDir,
    'Edge Box Configuration',
    'Enter your store information',
    'These settings can be changed later via the Edge Manager web UI.');

  ConfigPage.Add('Store ID:', False);
  ConfigPage.Add('Cloud URL:', False);
  ConfigPage.Add('API Key:', False);

  ConfigPage.Values[0] := GetEnv('STORE_ID');
  if ConfigPage.Values[0] = '' then
    ConfigPage.Values[0] := 'STORE_001';

  ConfigPage.Values[1] := 'https://api.deltapos.cloud';
  ConfigPage.Values[2] := '';

  // License key field
  LicenseLabel := TLabel.Create(ConfigPage);
  LicenseLabel.Parent := ConfigPage.Surface;
  LicenseLabel.Left := ConfigPage.Editor.Left;
  LicenseLabel.Top := ConfigPage.Editor.Top + 140;
  LicenseLabel.Caption := 'License Key:';

  LicenseKeyEdit := TEdit.Create(ConfigPage);
  LicenseKeyEdit.Parent := ConfigPage.Surface;
  LicenseKeyEdit.Left := ConfigPage.Editor.Left;
  LicenseKeyEdit.Top := ConfigPage.Editor.Top + 158;
  LicenseKeyEdit.Width := ConfigPage.Editor.Width - 110;
  LicenseKeyEdit.Text := '';

  GenerateKeyBtn := TButton.Create(ConfigPage);
  GenerateKeyBtn.Parent := ConfigPage.Surface;
  GenerateKeyBtn.Left := ConfigPage.Editor.Left + ConfigPage.Editor.Width - 105;
  GenerateKeyBtn.Top := ConfigPage.Editor.Top + 156;
  GenerateKeyBtn.Width := 100;
  GenerateKeyBtn.Height := 23;
  GenerateKeyBtn.Caption := 'Generate';
  GenerateKeyBtn.OnClick := @GenerateKeyClick;

  // Sync interval label
  DescLabel := TLabel.Create(ConfigPage);
  DescLabel.Parent := ConfigPage.Surface;
  DescLabel.Left := ConfigPage.Editor.Left;
  DescLabel.Top := ConfigPage.Editor.Top + 200;
  DescLabel.Caption := 'Sync Interval:';

  // Sync interval dropdown
  SyncIntervalCombo := TComboBox.Create(ConfigPage);
  SyncIntervalCombo.Parent := ConfigPage.Surface;
  SyncIntervalCombo.Left := ConfigPage.Editor.Left;
  SyncIntervalCombo.Top := ConfigPage.Editor.Top + 218;
  SyncIntervalCombo.Width := ConfigPage.Editor.Width;
  SyncIntervalCombo.Style := csDropDownList;
  SyncIntervalCombo.Items.Add('Every 1 minute');
  SyncIntervalCombo.Items.Add('Every 5 minutes');
  SyncIntervalCombo.Items.Add('Every 15 minutes');
  SyncIntervalCombo.Items.Add('Every 1 hour');
  SyncIntervalCombo.Items.Add('Once per day');
  SyncIntervalCombo.ItemIndex := 0;
end;

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := False;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  EnvFile, Content: string;
  SyncInterval: string;
begin
  if CurStep = ssPostInstall then
  begin
    case SyncIntervalCombo.ItemIndex of
      0: SyncInterval := '1m';
      1: SyncInterval := '5m';
      2: SyncInterval := '15m';
      3: SyncInterval := '1h';
      4: SyncInterval := '1d';
    else SyncInterval := '1m';
    end;

    EnvFile := ExpandConstant('{app}\edge-box\.env');
    Content :=
      'APP_NAME=DeltaPOS' + #13#10 +
      'APP_ENV=production' + #13#10 +
      'APP_KEY=' + #13#10 +
      'APP_DEBUG=false' + #13#10 +
      'APP_URL=http://localhost:8000' + #13#10 +
      'LOG_CHANNEL=stack' + #13#10 +
      'LOG_LEVEL=warning' + #13#10 +
      'DB_CONNECTION=sqlite' + #13#10 +
      'DB_DATABASE=' + ExpandConstant('{app}') + '\database\database.sqlite' + #13#10 +
      'DEPLOYMENT_MODE=offline-first' + #13#10 +
      'STORE_ID=' + ConfigPage.Values[0] + #13#10 +
      'CLOUD_API_URL=' + ConfigPage.Values[1] + #13#10 +
      'API_KEY=' + ConfigPage.Values[2] + #13#10 +
      'EDGE_BOX_API_KEY=' + LicenseKeyEdit.Text + #13#10 +
      'SYNC_INTERVAL=' + SyncInterval + #13#10 +
      'SESSION_DRIVER=file' + #13#10 +
      'CACHE_DRIVER=file' + #13#10 +
      'QUEUE_CONNECTION=sync' + #13#10 +
      'FILESYSTEM_DISK=local' + #13#10;

    SaveStringToFile(EnvFile, Content, False);
  end;
end;

function InitializeUninstall: Boolean;
begin
  Result := True;
end;
