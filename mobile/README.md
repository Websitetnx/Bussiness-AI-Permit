# PERMIT for Android and iOS

React Native / Expo client for applicants, BPLO administrators, legacy staff roles,
and City Treasurer accounts. It connects to the existing PHP/MySQL installation.
The website remains available alongside the mobile app.

## Run locally

1. Start Apache and MySQL in XAMPP. Use the existing database and server configuration;
   apply the migrations described in the root README if they are not installed yet.
2. In this directory, run:

   ```powershell
   npm ci
   Copy-Item .env.example .env
   npm start
   ```

3. Open the project with a compatible Expo Go installation, or use an Expo development
   build. Press `a` in the terminal for a configured Android emulator. The project uses
   [Expo SDK 55, React Native 0.83, and React 19.2](https://expo.dev/changelog/sdk-55).
4. Set the project base URL in `.env` or in the app's **Connection settings**:

   | Device | Example base URL |
   | --- | --- |
   | Android emulator on this PC | `http://10.0.2.2/git%20test/Business-Management-main%20-%20Copy` |
   | Physical phone on the same network | `http://YOUR-PC-LAN-IP/git%20test/Business-Management-main%20-%20Copy` |
   | Hosted installation | `https://permits.example.gov` |

   Enter the project URL, without `/api/mobile.php`. A phone's `localhost` refers to
   the phone. Apache must be reachable from the device; local firewall and Apache
   access rules may need to allow your development network.
5. Sign in with an existing applicant, administrator, or Treasurer account. Registration,
   email OTP verification, password recovery, and the locked first-admin setup are also
   available. Email functions still need the server's SMTP configuration.

HTTP is enabled only for local development when `EXPO_PUBLIC_ALLOW_HTTP=true`.
Release JavaScript accepts HTTPS only. Do not put database, SMTP, or OpenAI credentials
in Expo environment variables; `EXPO_PUBLIC_*` values are embedded in the app.

## Available workflows

- Applicant registration and OTP verification, login, recovery, and logout.
- Dashboard, new applications, required/conditional PDF and image uploads, optional
  location capture, renewal lookup and fee preview, status tracking, correction uploads,
  and document history.
- Annual/quarterly installment screens, payment evidence, verified receipts, released
  certificates with QR verification, and native PDF/file sharing.
- Staff review queue, approval/revision/rejection/release, AI document scan actions,
  reviewer assignments, fee and installment policy settings, user creation, analytics,
  and report/CSV exports.
- Treasurer payment management and financial reports, with the original role restrictions.
- In-app notifications and BPLO help messages. Notifications refresh on opening the
  Updates tab or pulling to refresh; operating-system push notifications are not configured.

Forms use a scrolling mobile layout. Dates use explicitly labelled ISO date inputs.
Report tables become readable cards; forecast values are shown as labelled data.
PDF exports use a mobile print layout and preserve the certificate's verification QR.

## Architecture

```text
mobile/App.tsx                 Native shell, navigation, sign-in lifecycle, updates, help
mobile/src/NativeScreen.tsx    Native forms, selectors, uploads, cards, and disclosures
mobile/src/api.ts              Multipart transport and encrypted session storage
mobile/src/model.ts            Field serialization, validation, and URL handling
mobile/src/print.ts            Native PDF sharing and QR rendering
api/mobile.php                Public PHP transport entry point
backend/mobile/endpoint.php   Allowed routes, mobile sessions, CSRF, and responses
backend/mobile/screen.php     Template semantics -> native screen JSON
```

The UI is **server-driven React Native**: the PHP adapter runs the existing authorized
controller, converts its rendered template into a restricted JSON tree, and sends that
tree to the native renderer. Text, forms, buttons, selectors, switches, and file pickers
are native components. Browser scripts and CSS are not executed on the device. This
keeps existing server validation, fee rules, file checks, OTP flows, audit events, and
payment safeguards in one implementation.

The only change to the shared web functions is a mobile-only branch in `redirect()`.
Normal web requests continue to receive their existing HTTP redirects. Mobile redirects
are returned as JSON so multipart submissions are not replayed.

When adding a server workflow, add its explicit route to `mobile_routes()` and test its
native form semantics. New behavior that depends on browser JavaScript needs a matching
native action; it is not automatically carried over by the adapter.

## Backend deployment

Deploy `api/mobile.php`, `backend/mobile/`, and the updated `backend/includes/functions.php`
together with the existing application. No mobile-specific database migration is needed.
PHP needs the DOM/libxml extensions as well as the existing project requirements.
Keep the project's Apache protections in place: `mobile/.htaccess` denies web access to
the mobile source and local environment files. With another web server, deny access to
`/mobile/` explicitly.

Sessions are isolated from browser cookies using a separate PHP session name. The native
app stores the opaque session ID and CSRF token in Expo SecureStore and sends the session
only to its configured server. Login rotates the session; logout invalidates it. Every
mobile POST requires CSRF validation, and active-account/role checks run on each signed-in
request. Preserve the `X-Permit-Session` header through any reverse proxy. Responses are
not cacheable. Use HTTPS in production.

Set the PHP `APP_URL` to the public installation URL to generate shareable certificate
verification links. The existing SMTP worker, backup jobs, and other scheduled server
tasks continue to run on the server.

## Checks

From this directory:

```powershell
npm run typecheck
npm test
npx expo export --platform all
```

The unit test command uses Node's TypeScript stripping; use Node 22.18+ or Node 24 LTS.
From the repository root:

```powershell
php tests/mobile_screen_test.php
php tests/mobile_http_test.php
```

The HTTP test creates a randomly named temporary database and app copy, disables email
delivery and external AI calls, and cleans up its own fixtures. It needs permission to
create/drop a test database. It checks authorization, CSRF, session rotation, uploads,
protected downloads, staff decisions, installment verification, receipts, release guards,
certificate QR data, renewal quotes/submissions, inactive users, and logout.

Bundle export checks JavaScript and Hermes compilation; it does not install or exercise
the native app. Before distribution, test file picking, location permission, SecureStore,
PDF sharing, keyboard behavior, and navigation on real Android/iOS devices. Real OTP
delivery and AI scans also need a staging server with those services configured.

## Installable builds

Set your organization's bundle/package identifiers in `app.config.ts` and configure an
HTTPS `EXPO_PUBLIC_API_URL` for your EAS build environment. The included `eas.json` has
an internal Android APK profile and a production profile:

```powershell
npx eas-cli build --platform android --profile preview
npx eas-cli build --platform ios --profile production
```

These commands require your Expo account and platform signing credentials. No app-store
upload or release is performed by this repository's checks. iOS simulator builds require
macOS; Windows can bundle iOS JavaScript and submit an EAS cloud build.
