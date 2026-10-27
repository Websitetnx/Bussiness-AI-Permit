# Operations update installation and use

For stored permit terms and annual/quarterly renewal payments, follow the
[migration 009 guide](PERMIT_INSTALLMENTS.md) after migration 008. Fresh schema
imports already include both. The notes below describe the shared operations
infrastructure; migration 009 replaces the legacy calendar-year expiration rule.

This update adds a transactional email outbox, document versions and scan history,
internal reviewer notes, reviewer assignment and overdue reminders, private QR
verification tokens, aggregate reports, and encrypted backup/restore commands.

## Upgrade an existing installation

1. Stop Apache and the scheduled workers while updating files and tables. Export
   the current database and copy `storage/uploads` to a private backup location first.
2. Copy updated project files, preserving `.env`, any legacy `config.local.php`, and `storage/uploads`.
3. In phpMyAdmin select your existing database and import
   `database/migrations/008_operations.sql`. Earlier migrations 002–007 must already
   be installed. Do not import the main schema or the example database dump over
   an existing database. A new installation uses the updated `database/schema.sql`.
4. Enable PHP `mbstring` for Unicode notification handling. Backup tools also
   require PHP `zip` with AES encryption support. Run `composer install` for PHPMailer.
5. Set `APP_URL` to the reachable portal root, for example
   `https://permits.example.gov/Business-Management`. Do not include a trailing file
   name. Keep your existing SMTP environment variables or private local config.
6. From the project directory run `php cli/operations.php backfill`. This records
   current AI results and creates verification tokens for existing released permits.
   Migration 008 already backfills the current document metadata. Files or scans
   deleted before this upgrade cannot be recovered by this update.
7. Restart Apache and schedule `php cli/operations.php run` every minute.

## Schedule the email worker and overdue alerts

On Windows, open Task Scheduler → Create Task. Select an account with access to
the project, database, and SMTP environment. Add a daily trigger that repeats
every 1 minute indefinitely. The action is:

- Program: `C:\xampp\php\php.exe`
- Arguments: `"C:\xampp\htdocs\Business-Management\cli\operations.php" run`
- Start in: `C:\xampp\htdocs\Business-Management`

Choose “Do not start a new instance” if the task is already running. Apache
`SetEnv` variables are not inherited by scheduled PHP CLI tasks. Use system
environment variables or the root private `.env` for both. Legacy `config.local.php` overrides these if present.
Run the task manually once and inspect Admin → Email deliveries.

Linux cron example (adjust paths and account):

```cron
* * * * * cd /srv/permit && /usr/bin/php cli/operations.php run >> /var/log/permit-worker.log 2>&1
```

Applicant emails use the registered account address, not the freely editable
business-contact address. Application events notify the owner and every active
administrator. Internal assignment/overdue events notify administrators only.
Permit expiration reminders notify only the applicant, both in the portal and by
email. Both queue workers check expiration without requiring a dashboard visit,
including deployments before migration 009 (within 60 days of expiration).
Each permit term has separate upcoming and expired events; repeated checks do not
queue duplicate emails. Existing in-app-only reminders do not prevent email delivery.
On migration 008 databases, apply `010_notification_audiences.sql` first to support
applicant-only recipients without installing the installment workflow. For a worker
restricted to one test account's expiration mail, schedule
`php cli/expiration-reminders.php USER_ID`; it also retries pending expiration mail.
Treasurers keep payment notices and access financial reports (collections,
outstanding balances and overdue installments after migration 009). OTP messages
remain synchronous because their short expiry and registration flow need immediate
delivery feedback; they are not replayed by the business-event worker.

Submission, status changes, failed document scans, replacements, payment submission
and payment decisions create durable notifications. No SMTP call occurs in those
transactions. Automatic upload scans, replacement scans, and manual admin scans make
an immediate post-commit delivery attempt for their document-correction alerts; the same outbox rows remain
available for worker retries if SMTP is unavailable. The worker rechecks recipient
authorization before sending and uses the current account email. Sent jobs are not
retried. Failed attempts are recorded
and automatically retried after 1, 2, 4, and 8 minutes, up to five total attempts.
Admin → Email deliveries can requeue an exhausted job while retaining attempt history.

Events and recipients have unique keys, and database advisory locks serialize workers.
Every retry carries the same Message-ID. SMTP cannot guarantee exactly-once delivery:
if the server accepts a message immediately before the worker crashes, a recovered
job may send it again. Such interrupted attempts are marked uncertain and delayed
five minutes before retry. “Sent” means accepted by SMTP, not guaranteed inbox placement.
Mailbox spam filtering and bounces must still be checked with the provider.

## Document history

Open an application → Document version history. Both the owner and BPLO can access
their authorized files and prior scan summaries; other applicants and treasurers cannot.
Each replacement keeps the original bytes, upload time, replacing time, and uploader.
Replacement scans and manual rescans preserve previous persisted results. Administrators can append internal
notes to a specific version; applicants cannot read or edit those notes. Full scan
snapshots are available to administrators. Use the existing application status notes
for correction instructions intended for the applicant.

## Reviewer assignments

Admin → Reviewer assignments supports all, mine, overdue, and unassigned filters.
Choose an active BPLO administrator and a future date/time in the configured
application timezone. Reassignments and deadlines are saved in history.
Assignment is workload ownership; all authorized BPLO administrators retain review
access. Internal due dates are operational targets, not a statutory processing claim.
Overdue notifications are generated once daily per assignment revision when status
is For Review. Needs Revision, Approved, Released, and Rejected pause overdue alerts.
After corrections, review/reschedule the deadline if needed. Inactive reviewers are
visibly flagged for reassignment.

## QR verification

Released certificates render a QR code locally with a vendored MIT-licensed QR
encoder. No external QR service receives permit data. The code contains only the
trusted APP_URL and an unguessable 256-bit token. APP_URL must be reachable from
the scanner's device; localhost URLs only work on the same device.

The public page exposes only permit number, current status, issue/effective dates and expiry.
It omits names, addresses, contact details, tax numbers, application IDs, payment
details and supporting documents. Tokens do not grant access to applicant pages.
Status is read live: unreleased records show Not currently released; older records
show Superseded after a newer release with the same permit number takes effect.
After migration 009, expiry and effective date use the stored BPLO-confirmed term;
legacy released dates are preserved. Before 009, expiry uses December 31 of the
issue year. QR verification
proves a record exists on this portal, not authenticity of outside DTI/SEC documents.
Use HTTPS and an official LGU domain. Disable query-string logging for this endpoint
if your hosting access logs would otherwise retain verification tokens.

## Reports

Admin → Reports and exports includes application counts, first-approval processing
times, verified payment collections, revision events and renewal counts. Reports
support a date range, CSV download and browser Print/Save PDF. Both selected days
are included. Processing uses calendar days from submission to the first approval
or release event, including correction waits; it does not use the latest edit time.
Counts by status reflect current status, not a reconstructed historical status.
Collections use paid_at and exclude unpaid/failed payments. CSV values are escaped
against spreadsheet formula injection. Reports contain aggregate metrics, not
applicant lists. With migration 009, the additional outstanding and overdue reports
include application/permit references for financial reconciliation. Treasurer
access is limited to these financial reports. Collections count verified
installments once, never adding the annual assessment on top of them.

## Backup and restore drill

Use a private directory OUTSIDE `htdocs` and the project, for example
`C:\permit-backups`, with permissions limited to the backup operator. These tools
do not upload backups anywhere or include API keys, local configuration or code.
Keep a separate secure copy of deployment configuration and the code commit ID.

Set `BACKUP_PASSWORD` to a strong passphrase of at least 16 characters in the
operator environment. Keep the recovery passphrase separately in a password manager.
Every ZIP entry, including its manifest, uses AES-256 encryption. ZIP entry names
remain visible; the contents are encrypted. Do not pass passwords on the command line.

Stop incoming requests and workers (in XAMPP stop Apache), then run:

```text
php cli/backup.php create C:\permit-backups\permit-2026-09-06.backup.zip
php cli/backup.php verify C:\permit-backups\permit-2026-09-06.backup.zip
```

The backup takes an exclusive maintenance lock; if any request or worker is active,
it refuses and asks you to retry. Keep schema migrations and other database writers
stopped. A consistent InnoDB snapshot includes all tables, current uploads, historical
requirements and payment proofs. Missing referenced files fail the backup. SHA-256
checksums cover every data/schema/file entry. Memory use scales with the largest
table and the ZIP contents; this implementation suits small/medium deployments.
Large deployments should use database-native streaming backups with equivalent
file consistency and recovery testing.

To test restoration, create an EMPTY database such as `permit_restore_drill` in
phpMyAdmin, set `RESTORE_DB_NAME=permit_restore_drill`, and optionally set
`RESTORE_DB_USER` and `RESTORE_DB_PASS` for a dedicated restore account. Then run:

```text
php cli/backup.php restore C:\permit-backups\permit-2026-09-06.backup.zip C:\permit-backups\drill-uploads
```

`drill-uploads` must not exist. Restoration refuses the configured live database,
nonempty target databases and existing upload destinations. Only restore trusted
archives created by this tool: schema restoration executes the backed-up table DDL.
The restore validates checksums before mutation, then compares restored table data
and every file checksum. Failure can leave empty tables or partial files in the
isolated target; inspect them and use another empty target for a retry.

For a drill, start a separate nonpublic copy of the app pointing to the restored DB
and upload directory. Keep SMTP, AI credentials and workers disabled. Sign in with
a test account, inspect an application and old/new files, check a receipt/certificate,
and record the archive name, date, table/file counts and operator result. The automated
integration test also performs an encrypted database+file backup and restore round trip.
Before a real recovery cutover, stop all writes, verify records and uploaded files,
reconcile queued mail against provider delivery records to avoid re-sending old jobs,
then switch database/upload configuration. Keep the original deployment available
for rollback until verification is complete.

Schedule a daily backup during a maintenance window, retain multiple dated copies
under your LGU's retention policy, keep an encrypted copy on separate storage, and
perform a restore drill at least monthly and after schema changes. Nothing here
automatically deletes old backups or live data.

## Test commands

Run `composer install`, PHP syntax checks, the existing tests, and
`php tests/operations_integration_test.php` against a disposable `permit_test_` database.
For a local installation with private configuration, prefer
`php tests/operations_isolated_test.php`: it creates disposable databases and an
isolated app copy, with real email/AI disabled, without changing live configuration.
The integration test requires an additional empty `permit_test_restore` database.
Never run fixture tests against production. GitHub Actions configures these databases,
tests migration 008 on the original schema, independently decodes a generated QR,
and runs the backup/restore round trip using synthetic data and a fake mail transport.
