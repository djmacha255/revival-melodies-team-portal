# Revival Melodies Team portal

A responsive PHP/MySQL member portal for the Revival Melodies Team (RMT). It includes member registration and sign-in, private verification-document uploads, an administrator approval workflow, a Jitsi meeting room, and a database-backed noticeboard.

## Requirements

- PHP 8.1 or later with `pdo_mysql`, `fileinfo`, and `mbstring` enabled
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` and `.htaccess` overrides enabled (or equivalent rewrite rules)
- HTTPS for production

## Local setup

### Quick Windows preview

After approving the one-time portable-runtime download, prepare the verified local runtimes and start PHP and MariaDB from PowerShell:

```powershell
.\tools\prepare-preview.ps1
.\tools\start-preview.ps1
```

Open `http://127.0.0.1:8000/`. The preview provisions an isolated MariaDB database on `127.0.0.1:3307`; its data, logs, and downloaded runtime files are kept under the ignored `.local-preview/` folder and are not included in the hosting ZIP. The demo administrator is `preview.admin@example.test` / `RmtPreview!2026`. Do not reuse this demo account or password in production. Stop the preview with `.\tools\stop-preview.ps1`. To explicitly reset the local demo database, stop the services and run `.\tools\start-preview.ps1 -ResetDatabase`.

1. Create a MySQL database and user, grant the user access to the database, select that database, then import the schema:

   ```sh
   mysql -u rmt_user -p rmt_ministry < schema.sql
   ```

2. Configure the connection before starting PHP. Local development defaults are `127.0.0.1`, database `rmt_ministry`, username `root`, and an empty password. For example, in PowerShell:

   ```powershell
   $env:DB_HOST = "127.0.0.1"
   $env:DB_NAME = "rmt_ministry"
   $env:DB_USER = "rmt_user"
   $env:DB_PASSWORD = "use-a-long-random-password"
   ```

   Alternatively, copy `app/config.example.php` to `app/config.local.php` and enter your local database settings there. The deployment package creates this private configuration file for editing. Set environment variables or the private configuration file in production; never put production credentials in a public web directory.

3. Point the web server document root at the project's `public` directory. For local PHP development:

   ```sh
   php -S 127.0.0.1:8000 -t public
   ```

   The built-in PHP server does not read `.htaccess`; to exercise pretty URLs locally, use Apache or a development router that forwards requests to `public/index.php`.

4. Create the first administrator from a trusted command line after importing the schema:

   ```sh
   php create_admin.php "RMT Administrator" admin@example.com "a-long-unique-password"
   ```

   The password must be at least 12 characters. Do not expose this script through the web server; the application deliberately permits administrator creation only from CLI. If your shared host does not provide CLI PHP, register the account normally and promote it through your database control panel:

   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'admin@example.com';
   ```

5. Visit `/` and register a member account. The first administrator can sign in at `/login` and review member verification requests at `/admin`.

## Hosting / deployment

- Build the cPanel/shared-hosting upload archive in PowerShell:

  ```powershell
  .\tools\package-deployment.ps1
  ```

  The script creates `dist\RMT-Deployment.zip`. The archive contains `public_html/` and `rmt-private/` as siblings, includes the Apache rewrite rules, and keeps application code, schema, admin tool, configuration, and signed-form storage outside the public document root. Review `deployment/DEPLOY.txt` before extracting the ZIP. Enter the host's database credentials in `rmt-private/app/config.local.php` after extraction.
- For a custom virtual-host document root, point the site at `public/`, keep `app/` and `storage/` outside the web root, and configure the database through environment variables or `app/config.local.php`.
- Configure the host-provided MySQL host, database name, user, and password as `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`.
- Ensure PHP can write to `public/uploads/` and `storage/verification/`. Profile pictures are public image files; signed PDFs are stored outside the document root and only downloadable by administrators.
- Ensure `file_uploads` is enabled and `upload_max_filesize` / `post_max_size` allow the 10 MB verification PDF limit (set `post_max_size` above the file limit to allow multipart overhead).
- Enable HTTPS. Session cookies are marked `HttpOnly` and `SameSite=Lax`, and marked `Secure` when HTTPS is detected.
- Tailwind CSS is compiled into `public/assets/tailwind.css` before deployment, so utility styling does not require a browser-side Tailwind CDN. Rebuild it after changing Tailwind classes with `npm ci` and `npm run build:css`.

## Main routes

| Route | Access | Purpose |
| --- | --- | --- |
| `/` | Public | Ministry landing page |
| `/register` | Public | Member account registration |
| `/login` | Public | Member / administrator sign-in |
| `/dashboard` | Signed in | Member profile and verification request |
| `/admin` | Administrator | Directory and verification approval |
| `/admin/verification/{id}/download` | Administrator | Private signed-form download |
| `/meeting` | Signed in | Embedded RMT Jitsi room and meeting agenda |
| `/chat` | Signed in | Persistent team noticeboard |

The noticeboard checks for new database posts every five seconds while open. It is polling-based (not a WebSocket service), so it works on standard PHP shared hosting.

## Data and operational notes

- Select the target MySQL database before importing `schema.sql`; the schema does not create or select a database, so it works with hosting-provider database prefixes. All member, verification, and announcement data is stored in MySQL.
- Never commit `.env` files or production credentials. Use the host's environment configuration.
- Back up the MySQL database and the private `storage/verification/` directory together so submitted verification documents remain available.
- Account passwords use PHP's `password_hash()` / `password_verify()`. Forms use session CSRF tokens, SQL uses prepared statements, and output is HTML-escaped.
- The meeting iframe requires browser camera/microphone permission and network access to `meet.jit.si`.

## Folder layout

```text
app/                 Bootstrap, database connection, shared helpers
public/              Web document root, front controller, CSS, profile images
storage/verification/Private signed PDFs
schema.sql           MySQL schema
create_admin.php     CLI-only first-admin provisioning
```
