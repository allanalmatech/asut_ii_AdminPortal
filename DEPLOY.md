# Upload ASUT II Control Center to shared hosting

## Requirements

- PHP 8.0+ (PHP 8.2+ recommended), PDO MySQL, OpenSSL and JSON.
- MySQL **8.0.16+** or MariaDB **10.3+**, including **10.3.39-MariaDB-cll-lve**, with InnoDB tables and `innodb_flush_log_at_trx_commit=1`.
- Apache/LiteSpeed-compatible `.htaccess`, URL rewriting and authorization headers.
- A valid HTTPS certificate for the public client API.

## Installation

1. Create a database and database user in the hosting control panel and grant privileges on that database. Setup needs table/index/foreign-key creation; upgrades may need ALTER. The application uses its configured `asut2_` prefix to separate its tables from existing applications.
2. Upload and extract `ASUT-II-Admin-Portal.zip` in a private hosting directory outside `public_html`, then move its extracted contents into, for example, `public_html/asut_admin/`. `index.php`, `admin.php`, `api.php`, `download.php`, `.htaccess`, `assets`, `patches`, `app`, `config` and `storage` must be at the same level. Include hidden `.htaccess` files. Keep the owner-only archive outside the public web directory; the supplied root rules also deny direct ZIP downloads.
3. Copy `config/config.example.php` to `config/local.php`. Enter the database host, port, name, user and password. Replace `setup_token` with a new random secret of at least 24 characters. Keep this configuration private.
4. Preserve the host's existing `storage/keys` when updating. The ZIP includes the client-pinned public key; matching private keys are included only when the packaging workspace owns them. For a new installation, supply the matching private keys or follow the authenticated key-export procedure below before distributing the client. If using a storage path outside `public_html` (preferred when available), move the complete storage folder, including its keys, and set `storage_path` accordingly. Give PHP write permission to that private storage folder.
5. Open `https://almatechconsults.com/asut_admin/setup.php` (or your own portal's setup URL). Enter the configured setup key and choose the administrator credentials. Installation creates the tables and catalog atomically seeds the admin account. An installed portal cannot be reinitialized to replace its administrator.
6. The portal root is the public product/pricing page. Sign in at `https://almatechconsults.com/asut_admin/admin.php` (or your own portal's `admin.php`). Configure tariffs, enable the implemented devices you want to offer, and create active clients with their authorized HWIDs. New installations seed the published credit costs but leave hosted tariffs disabled until an administrator enables them. Existing accounts and tariffs are preserved during code updates.
7. The client source `App Source Code/ManagedSystem/ClientAssets/client-config.json` field `apiBaseUrl` is configured for `https://almatechconsults.com/asut_admin/`. Rebuild/distribute the updated client when changing the host. The URL is not displayed or editable in the login form. The client validates the matching server public key and requires a reachable API before unlocking.

If the host generated its own signing keys during setup, export its public key
through the authenticated `GET api/admin/server-key` endpoint and pin that key
in the client source before building. The response contains only the public
key, never the private signing or settlement keys. Existing hosting keys remain
on the server during code updates.

### Git updates on this host

Pull the portal repository to update PHP, setup and schema code. Preserve the
host's existing `config/local.php` and `storage/keys`. The repository includes
the required device catalog, images and patch ZIPs. Deploy the new `admin.php`,
`download.php`, `landing.css`, `landing.js` and `app/Product.php` together with
`index.php` and the other changed source files. For a first deployment with an
existing client public-key pin, supply the matching signing keys as described
above. Run `setup.php` only for a first installation; installed portals apply
non-destructive schema upgrades through the API. Setup checks for missing
catalog files, PHP extensions and writable storage. After submitting a valid
setup key, any database/installation error is shown to the installer instead
of only the generic configuration message.

The Windows client folder contains only the server's public key. Keep the administrator hosting package, database credentials, signing private key and settlement key out of client distributions. Back up the database and server keys together; do not generate replacement signing keys casually for an existing client fleet.

## Hosting checks

- `/api/health` should identify **ASUT II Control Center** and **MySQL/MariaDB InnoDB**.
- The root should show the public purchase/pricing page; `admin.php` should show the administrator login and dashboard inside the hosting subdirectory.
- Check the purchase email link, all 15 device prices and the download/request button on desktop and mobile.
- Direct web requests to `config`, `app`, `storage`, `tools` and `patches` must be denied. Patches are delivered only through the authenticated API.
- Ensure TLS is enabled/forced by the hosting panel. The desktop refuses remote HTTP API URLs.

The upload contains no local database, local demo passwords, Windows runtimes or .NET server. The PHP patch download endpoints read the bundled ZIPs without requiring a PHP ZIP extension.

## Optional cron

Unused authorizations are expired during account/admin activity. For regular cleanup during idle periods, configure the host's PHP CLI cron:

```text
php /home/ACCOUNT/public_html/asut-admin/tools/expire.php
```

Execute it every minute. It only refunds expired authorizations that never entered execution; it does not refund jobs whose physical outcome is unknown.

## Operations

Use **Clients & hardware** to suspend, activate, rebind hardware, reset passwords, adjust units or set expiry. Access-related edits revoke existing client sessions. Use **Device tariffs** for prices; existing operations retain the tariff captured when they were authorized.

Use **Credit vouchers** to generate codes, optionally binding them to an HWID. Save the complete codes when shown. Redeemed vouchers cannot credit another account or be reloaded, and only masked references are retained by the portal.

Use **Unlock operations** and **Unit ledger & audit** to review charges, automatic refunds and interrupted jobs. Administrator refunds are operation-scoped and cannot create repeated credits for the same job.

## Client updates and notifications (2.1)

The first API request after deployment applies the non-destructive schema upgrade
for notifications/read receipts. It retains existing clients, balances, tariffs,
ledger entries and signing keys. The configured database user needs CREATE/ALTER
privileges for application schema upgrades, as during initial setup.

In **App updates**, enter the latest software version, numeric build number,
HTTPS download link and grace period in hours. Publishing starts that grace
period immediately; zero hours makes the newer build mandatory immediately.
Older clients are blocked from starting new device operations after the deadline.
Existing operation settlement/refunds continue to work. Disable enforcement to
withdraw the policy. The app opens the administrator's download link in the
user's browser.

In **Notifications**, select one client or **All clients**, enter the title and
message, and optionally set an expiry. Online clients receive notices on their
next account check (normally within 30 seconds) or when refreshing. Read receipts
are per client; withdrawing a notice removes it from subsequent deliveries.

## Public product page and pricing

The public offer is **$100 USD with 100 credits included**. The public device
table shows credits only, with no currency-conversion display.
`app/Product.php` contains the offer constants and all 15 published device
credit prices. New installations use its credit costs as initial tariffs;
changing it does not overwrite an installed portal's tariffs. Update existing
tariffs in **Device tariffs** when changing published pricing.

### G5TS catalog update

Deploy `app/Installer.php`, `app/Product.php`, `assets/device_catalog.json`,
`assets/devices/G5TS.png`, and `patches/G5TS.zip` with the matching portal release.
The first API request applies the non-destructive version-6 schema update.
An existing portal receives the ZTE G5TS entry with an initial 10-credit tariff.
Existing G5TS tariff edits and enable/disable choices are preserved. Verify the
requested 10-credit value in **Device tariffs** after deployment.

The corresponding Windows client release is **2.2.0**, build **2026103102**.
The G5TS Lock Device action costs 2 credits, confirmed before authorization;
unlocking uses the current administrator-configured tariff. Existing balances,
accounts, tariff edits and transaction history are preserved.

### Protected workflow deployment

Pull the complete portal release, including `app/Records.php`, `app/Lite.php`,
`schema.sql` and `patches/lite-workflows.bin`. Keep `config/local.php` and all
existing private keys. The workflow package is encrypted to the server's existing
RSA public key and is unpacked into versioned directories beneath the configured
private `storage_path/workflows` on first authorized use. PHP needs its existing
OpenSSL support and zlib. No extra worker service is required.

The migration adds action/mode fields and the encrypted workflow-transcript
table. Client results cannot settle server-orchestrated operations through the
normal completion endpoint. Interrupted jobs with uncertain hardware outcomes
retain their reservation for administrator reconciliation; verified failures
and pre-activation cancellation return credits once. Running jobs retain their
pinned workflow version across a subsequent resource update.

The separate Windows package uses normal ASUT II branding and the same account,
credit ledger and themed router-login screen. Install the matching Windows
executable after pulling the API release.

The purchase button opens an email to `asutdev@gmail.com`. After arranging
payment, the administrator creates/activates the client's HWID-authorized
account and grants the included 100 credits. The public page does not process
payments or automatically credit accounts.

### Enable the Windows app download

Choose either of these supported download configurations:

- Publish the customer EXE at an HTTPS address and enter that address in
  **App updates**. The public page uses the saved download link; update
  enforcement can remain disabled when only publishing a download.
- Separately upload `App Build/ASUT II Portable.exe` as
  `storage/releases/ASUT-II-Portable.exe` under the configured private
  `storage_path`. Alternatively set `client_download_file` in `config/local.php`
  to its absolute private path. `download.php` streams it and supports resumed
  downloads; direct web access to `storage` stays denied.

The large customer EXE is not included in Git or the PHP hosting ZIP. Until a
readable private file or HTTPS download link is available, the landing page
offers **Request app download** by email. Keep the displayed release metadata
and any **App updates** version consistent with the uploaded executable.
