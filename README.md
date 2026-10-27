# PERMIT — Web-Based Business Permit Management System

## React Native mobile app

An Expo / React Native app for Android and iOS is available in [`mobile/`](mobile/README.md).
It supports applicant, administrator/staff, and Treasurer workflows through the existing
PHP/MySQL backend. Native forms, uploads, review controls, notifications, payments,
receipts, and certificates use the new `api/mobile.php` transport. The existing website
continues to work. See the [mobile setup and architecture guide](mobile/README.md).

## Permit terms and installment payments (migration 009)

Adds BPLO-confirmed one-year permit dates, required Annual/Quarterly renewal
selection with server-calculated previews, separate installment evidence and
receipts, configurable release controls, reminders, and financial reports.
Quarterly payments never change permit validity. Existing records and manual
admin scans are preserved. See the [migration and configuration guide](docs/PERMIT_INSTALLMENTS.md).
Existing installations must apply migration 009 once after 008 and configure
the approved LGU policy in **Fee settings**; fresh schema imports already include it.

## Operations update (migration 008)

Adds durable email notifications with retries and delivery history, document versions
and reviewer notes, reviewer assignments and overdue alerts, QR permit verification,
CSV/printable reports, and encrypted database/upload backup and restore tools.
See [installation and operations guide](docs/OPERATIONS.md) for the migration,
worker scheduling (including XAMPP Task Scheduler), and safe restore-drill steps.

PERMIT is a PHP 8 and MySQL application for applicant registration, secure sign-in, new business-permit applications, permit renewal, document submission, status tracking, and LGU administrator review.

## Frontend and backend structure

The application remains a server-rendered PHP app. Templates and browser assets
are separated from request handling and shared backend logic:

```text
frontend/
  views/          Applicant and public page templates
    admin/        Administrator page templates
  layouts/        Shared header, navigation, and footer markup
  partials/       Reusable payment, renewal, and policy form markup
  assets/         CSS, JavaScript, logos, and the vendored QR renderer
backend/
  controllers/    Authentication, form processing, downloads, and page data
    admin/        Review, manual scans, payments, and management actions
    api/          JSON endpoints and document re-upload handling
  includes/       Database, authorization, AI scanning, email, and shared services
includes/         Compatibility loaders for existing CLI tools and integrations
admin/, api/      Public URL entry points (small controller loaders)
*.php             Existing public page URLs and deployment configuration
```

For example, `apply.php` loads `backend/controllers/apply.php`, which validates
the request and prepares data before rendering `frontend/views/apply.php`.
Edit the template for markup, `frontend/assets/styles.css` for styling, and the
controller or shared backend service for behavior. Templates do not query the
database or process form submissions.

Existing page URLs, POST targets, CLI commands, and private `config.local.php`
settings are unchanged. Automatic upload/re-upload checks, manual admin **AI scan**
and **Scan again** controls, and correction-email handling remain enabled.
There is no separate frontend server or build command: keep serving the project
root through XAMPP as before.

Apache blocks direct requests to `backend/` and PHP files under `frontend/`.
Controllers and templates also reject direct execution in PHP. The root
`.htaccess` preserves old CSS, JavaScript, and logo URLs when `mod_rewrite` is
enabled; rendered pages use their new `frontend/assets/` URLs directly.

Run `php tests/layer_separation_test.php` for structural checks and
`php tests/reupload_document_http_test.php` for isolated page, upload, automatic
scan, manual scan, email-attempt, and access-control regression checks. The HTTP
test creates its own temporary database and files, uses a local fake AI service,
and disables SMTP; it does not send real emails or scan live applicant files.

## Included features

- Applicant sign-up with email OTP verification and sign-in using PHP sessions
- Password hashing with `password_hash()` and `password_verify()`
- Email OTP password recovery without account-discovery responses
- One-time first-administrator setup that locks after use
- Additional administrator creation from the protected admin workspace
- Role-based authorization for applicant, administrator, and payment-only Treasurer pages
- New permit and renewal records stored in MySQL
- Four standard and ten conditional document uploads
- Required Occupancy Permit or Affidavit of Undertaking alternative
- Server-side file type and 5 MB size validation
- Protected document viewing through an authorized PHP endpoint
- Applicant status timeline and administrator review queue
- Approval, release, revision, rejection, notes, notifications, and audit logs
- Post-approval fee assessment and payment submission
- Protected payment-confirmation uploads with administrator or Treasurer verification
- Release guard that requires verified payment, printable payment receipts, and released business certificates
- Configurable Philippine permit-fee formula using declared capital or gross sales
- Itemized LBT, Mayor's Permit, regulatory, inspection, BFP, barangay, and community-tax assessment
- AI-assisted PDF/image requirement scanning with structured findings
- Predictive workload, processing-time, backlog, and revision analytics
- Optional AI management summaries based only on aggregate statistics
- Optional applicant geolocation with accuracy data and administrator map verification
- CSRF protection and prepared PDO statements

## Requirements

- PHP 8.0 or later
- MySQL 8 or MariaDB 10.5+
- PHP extensions: `pdo_mysql`, `fileinfo`, and `curl`
- [Composer](https://getcomposer.org/) for installing PHPMailer
- Apache, Nginx, XAMPP, WAMP, or a similar PHP server

GitHub Pages cannot run PHP. Deploy this repository to a PHP-capable server.

## XAMPP setup

1. Copy the project folder to `C:\xampp\htdocs\Business-permit`.
2. Start Apache and MySQL in XAMPP.
3. Open a terminal in the project folder and install the PHP dependencies:

   ```text
   composer install
   ```

4. Open phpMyAdmin and import `database/schema.sql`.
5. Copy `.env.example` to `.env` in the project root, then edit your private settings there (do not overwrite an existing `.env`):

   ```dotenv
   DB_HOST=127.0.0.1
   DB_NAME=permitflow
   DB_USER=root
   DB_PASS=
   ```

6. Configure SMTP as described below, then restart Apache.
7. Make sure `storage/uploads` is writable by PHP.
8. Open `http://localhost/Business-permit/setup-admin.php` and create the first administrator.
9. Sign in at `http://localhost/Business-permit/login.php`.

The setup page automatically locks after the first administrator account is created. Public sign-up always creates an applicant account.

## Hosted-server configuration

Database values can be supplied in `.env` using `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS`. Use a dedicated database account on hosted servers. Before first-admin setup on a non-local server, also set `ADMIN_SETUP_KEY`; the setup page will require that key.

PHP loads the root `.env` for web requests and CLI commands. Existing server/process environment variables take precedence, including explicitly empty values. Legacy `config.local.php` remains supported and overrides both; remove migrated entries from that file to manage them through `.env`.

Use one `KEY=value` per line. Single and double quotes preserve spaces, `#`, `$`, and `=` literally. Escape a backslash or the matching quote with a backslash inside quoted values. Variable expansion, shell commands, and multiline values are not supported. Invalid entries fail with a line number without exposing their contents.

Keep API keys, SMTP credentials, database credentials, `ADMIN_SETUP_KEY`, and optional `BACKUP_PASSWORD` in `.env`. Keep private files in `private/` or `secrets/`, and applicant documents in the protected `storage/uploads/` directory. Backup archives must remain outside the project and web root as required by the backup CLI. `.gitignore` excludes local environment variants, credentials, logs, SQL exports, and private storage while retaining sanitized `.env.example` templates, the database schema/migrations, and access-control files.

Apache must allow this project's `.htaccess` rules. They deny direct access to environment files, private directories, keys, logs, and database exports. Nginx or other servers need equivalent deny rules; PHP's development server does not enforce `.htaccess`. Restrict filesystem access to `.env` to the application and deployment accounts. Mobile `EXPO_PUBLIC_*` variables are public app settings and must never contain backend secrets.

Ignoring a file does not remove it from an existing Git index or history. If credentials were previously committed or shared, revoke/rotate them and remove the private files from tracking before the next commit.

## Email OTP and password recovery setup

Install the Composer dependencies before using account email features:

```text
composer install
```

For an existing PERMIT database, import:

```text
database/migrations/007_auth_otp.sql
```

Configure the SMTP account in the root `.env` to deliver verification and password-reset codes:

```text
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=your-smtp-username
SMTP_PASSWORD=your-smtp-password
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=PERMIT
DOCUMENT_ALERT_ADMIN_EMAIL=admin@example.com
DOCUMENT_ALERT_ADMIN_NAME=PERMIT Administrator
APP_URL=https://permits.example.gov
```

Apache `SetEnv` or system environment variables can also supply these settings. Restart Apache/workers after changing server variables; `.env` is read when a new PHP process/request loads configuration.

Use `tls` with the submission port recommended by your mail provider (commonly `587`), or `ssl` only when the provider requires implicit TLS (commonly port `465`). Keep SMTP credentials out of the repository and browser JavaScript.

For Gmail, enable 2-Step Verification and create a Google **App password** for SMTP. Put that generated app password in `SMTP_PASSWORD`; do not use the normal Google account password. Google Workspace administrators may also need to allow app-password or SMTP access for the sending account.

Application events now queue email to the registered applicant and active administrators from `users`. `DOCUMENT_ALERT_ADMIN_EMAIL` applies only to the legacy template helper, not the new workflow. Set `APP_URL` to the public portal root. Schedule `php cli/operations.php run` to deliver queued messages and retry failures; monitor Admin → Email deliveries. SMTP failures never roll back saved applications.

### Applicant account verification

Public registration creates only applicant accounts. After submitting the registration form, the applicant is sent to `verify-registration.php`, receives a one-time code by email, and must verify it before signing in. Codes expire, are single-use, and a newly issued code replaces the previous code. If delivery is delayed, use the resend action after its cooldown instead of creating another account. Never share an OTP with staff or another user.

### Forgotten passwords

Use **Forgot password?** on the sign-in page to open `forgot-password.php`, submit the account email, enter the emailed code on `verify-reset-otp.php`, and choose a new password on `reset-password.php`. For privacy, the request page gives the same response whether or not an active account exists. A reset code expires, can be used only once, and is invalidated after a successful password change; requesting a newer code invalidates the older one.

Run the standalone OTP regression check with:

```text
php tests/auth_otp_test.php
```

## AI feature setup

PERMIT uses the [OpenAI Responses API](https://developers.openai.com/api/docs/guides/file-inputs) to analyze permit PDFs and images. Structured Outputs return consistent document type, quality, confidence, extracted fields, issues, and human-review indicators.

For an existing PERMIT database, import:

```text
database/migrations/002_ai_features.sql
```

Set these server environment variables and restart Apache/PHP:

```text
OPENAI_API_KEY=your-project-api-key
OPENAI_MODEL=gpt-5.6-luna
AI_DAILY_SCAN_LIMIT=100
```

For local XAMPP, add these values to the root `.env`. Never commit an API key to GitHub or place it in browser JavaScript or mobile `EXPO_PUBLIC_*` settings.

Optional setting:

```text
ALLOW_SENSITIVE_AI_SCAN=false
```

Medical-result scanning is blocked by default. Enable it only after the LGU completes its privacy and legal review. Every API request uses `store: false`, but administrators must still confirm that sending a document to the configured API project is authorized. OpenAI documents its current retention behavior in its [data controls guide](https://developers.openai.com/api/docs/guides/your-data).

The statistical forecast continues to work without an API key. The API is used for document interpretation and an optional narrative based only on aggregate, non-personal metrics. AI never makes the final permit decision; BPLO reviewers remain responsible for approval or rejection.

### Automatic upload checks

New applications, renewals, and corrected replacement files are checked automatically after the upload is safely stored. A completed scan requests document revision only when the detected type does not match, quality is below 40%, or identification confidence is below 30%. Other AI observations remain advisory.

Missing configuration, quota exhaustion, an unavailable API, rate limiting, and failed scan storage are non-blocking: the application continues to normal BPLO review. Medical results are skipped unless `ALLOW_SENSITIVE_AI_SCAN=true`. Applicants can see per-document results and replace files needing revision or with unavailable checks while awaiting review. Administrators can review the saved results or use **AI scan** and **Scan again** to run a manual check. Every replacement triggers a fresh automatic assessment of that file without requiring an admin action. Automatic and manual scans share the configured daily allowance.

`AI_DAILY_SCAN_LIMIT` counts individual documents, including replacement attempts. Set it in `.env` for both web and CLI; Apache `SetEnv` can override it, and legacy `config.local.php` overrides both. The admin review page shows when the daily allowance is exhausted.

When a completed scan marks documents invalid, PERMIT queues HTML and plain-text alerts to the registered applicant and active administrators. Messages include the reference, failed requirement, reasons, observations and authorized application links. Replacement scans use the same queue; event/recipient keys suppress duplicate jobs. Previous uploads and scans remain in protected version history.

Run the rule-boundary regression check with:

```text
php tests/auto_scan_rules_test.php
```

## Geolocation setup

For an existing PERMIT database, import:

```text
database/migrations/003_geolocation.sql
```

Applicants can explicitly select **Use current location** on new applications and renewals. PERMIT saves latitude, longitude, device-reported accuracy, and capture time, then provides administrators with an OpenStreetMap link. The written address remains required and location is optional, so an applicant can continue if permission is denied or GPS is unavailable.

Browser geolocation requires HTTPS in production. `http://localhost` is suitable for local development, but a hosted deployment must use a valid HTTPS certificate. No map or reverse-geocoding API key is required.

## Payment workflow setup

For an existing PERMIT database, import:

```text
database/migrations/004_payment_workflow.sql
```

When BPLO approves an application, the server stores its assessment and BPLO confirms
the permit dates. Applicants submit an authorized payment method, transaction or
treasury reference, and payment confirmation for each obligation. BPLO or a
payment-only Treasurer verifies or rejects the evidence and issues a receipt for
each verified payment. Treasurer accounts cannot access application decisions,
document scans, analytics, fee settings, or user management. Annual release requires
full verified payment; quarterly release requires the configured LGU release
amounts to be verified, without marking the entire assessment paid. Released permits
have a protected printable certificate. See [installment setup](docs/PERMIT_INSTALLMENTS.md)
for migration 009, eligibility, deadlines, history preservation, and release rules.

For databases created before the Treasurer role was added, run:

```text
database/migrations/006_treasurer_role.sql
```

This version records and verifies payment evidence; it does not directly transfer money or connect to a third-party payment processor. Configure and publish only payment channels officially authorized by the LGU.

## Automatic fee assessment setup

For an existing PERMIT database, import:

```text
database/migrations/005_fee_assessment_formula.sql
```

Then sign in as an administrator and open **Fee settings**. Enter the rates and fixed charges from the current city or municipal tax ordinance. No tax or fee amount is assumed by the source code.

For new applications, PERMIT uses declared capital as the configurable LBT basis. For renewals, it uses previous-year gross sales. The calculated total is:

```text
Total = Local Business Tax
      + Mayor's Permit / license fee
      + regulatory and applicable inspection fees
      + BFP fire safety inspection fee
      + barangay clearance fee
      + community tax certificate fee
```

The BFP percentage is applied to the Mayor's Permit and regulatory-fee subtotal. If a fee is collected separately or does not apply in the LGU, set it to `0.00`. Each approved application stores an itemized assessment snapshot with its payment record, so later schedule changes do not rewrite past assessments. The result remains subject to BPLO and City Treasurer verification.

## Main database tables

- `users`
- `businesses`
- `applications`
- `application_documents`
- `application_status_history`
- `payments`
- `permit_fee_settings`
- `permit_business_type_rates`
- `notifications`
- `audit_logs`
- `document_ai_scans`
- `ai_analytics_reports`

## Permit requirements

Standard required uploads:

1. DTI / SEC / CDA Registration
2. BFP Application Form
3. BFP Questionnaire
4. Consent Form

Conditional uploads:

1. Lease Contract for Private Building
2. FSIC of Occupancy Valid for 9 Months
3. Occupancy Permit
4. Tax Declaration — Current Year
5. X-Ray Result and Stool Examination
6. NGA Clearance
7. Affidavit of Undertaking in Absence of Occupancy
8. Building Owner's Business Permit
9. Fire Safety Inspection Certificate — Current Year
10. Sanitary Permit — Current Year

Applicants must submit either the Occupancy Permit or the affidavit alternative.
