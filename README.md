# ASUT II Admin Portal

This folder is the authoritative PHP public website, dashboard and API source.

- `index.php`: public product, purchase and device-pricing page.
- `landing.css` / `landing.js`: responsive public styling, navigation and device search/filtering.
- `app/Product.php`: UGX 300,000 purchase offer, 100 included credits and all 14 device prices.
- `admin.php`: administrator dashboard and login markup.
- `dashboard.css` / `branding.css`: interface styling and official logo.
- `dashboard.js`: interface interactions.
- `api.php` / `app/`: authentication, accounts, tariffs, vouchers and billing.
- `setup.php`: web installer; `tools/setup.php`: local CLI setup.
- `download.php`: administrator-published HTTPS download or private EXE streaming with resume support.

## Local development

From the sibling **App Source Code** folder, run:

```powershell
& ".\Start ASUT II Control Center.cmd"
```

The public page is served at **http://127.0.0.1:5088/**; administrator sign-in
is at **http://127.0.0.1:5088/admin.php**. The development watcher copies this
folder to `C:\xampp4\htdocs\asut-admin`. Edit this source folder and refresh
the browser.

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
& $python ".\ManagedSystem\package_portal.py" --configured
& $python ".\ManagedSystem\verify_release.py"
```

Upload `App Build/ASUT II Managed - Ready/ASUT-II-Admin-Portal.zip` and follow
`DEPLOY.md` / the build's `HOSTING-SETUP.md`. The standard ZIP excludes local
database files and `config/local.php`. The `--configured` option also produces
an owner-only `ASUT-II-Admin-Portal-Configured-PRIVATE.zip` with the host
configuration. Packages include the client-pinned public key; private signing
keys are included only when the workspace owns that signer. Preserve existing
hosted private keys when updating.

## Public purchase and download

The purchase link arranges payment and activation by email. Administrators
activate the client account/HWID and grant its included 100 credits manually.
Published prices live in `app/Product.php`; existing live tariffs are managed
separately through **Device tariffs**.

To enable direct download, set an HTTPS link in **App updates** or separately
upload the customer EXE to the private storage path
`releases/ASUT-II-Portable.exe`. `client_download_file` can override this path.
The large EXE is not part of Git or either PHP ZIP. Without a configured file
or link, the landing page offers a working email request. See `DEPLOY.md`.
