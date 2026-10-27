# ASUT II Admin Portal

This folder is the authoritative PHP dashboard/API source.

- `index.php`: dashboard and login markup.
- `dashboard.css` / `branding.css`: interface styling and official logo.
- `dashboard.js`: interface interactions.
- `api.php` / `app/`: authentication, accounts, tariffs, vouchers and billing.
- `setup.php`: web installer; `tools/setup.php`: local CLI setup.

## Local development

From the sibling **App Source Code** folder, run:

```powershell
& ".\Start ASUT II Control Center.cmd"
```

The portal is served at **http://127.0.0.1:5088/**. The development watcher
copies this folder to `C:\xampp4\htdocs\asut-admin`. Edit this source folder
and refresh the browser.

Local administrator credentials are in:

```text
..\App Source Code\ManagedSystem\data\local\private\local-access.json
```

Use its `adminUsername` and `adminPassword` fields. Client-app credentials are
the separate `demoUsername` and `demoPassword` fields.

## Package for hosting

From **App Source Code**:

```powershell
$python = "$env:LOCALAPPDATA\Programs\Python\Python313\python.exe"
& $python ".\ManagedSystem\package_system.py"
& $python ".\ManagedSystem\verify_release.py"
```

Upload `App Build/ASUT II Managed - Ready/ASUT-II-Admin-Portal.zip` and follow
`DEPLOY.md` / the build's `HOSTING-SETUP.md`. The package includes the matching
server signing keys and excludes local database files and local configuration.
