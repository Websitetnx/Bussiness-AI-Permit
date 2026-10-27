# Permit dates and annual / quarterly renewal payments (migration 009)

This update stores permit validity separately from payment obligations. It does
not prescribe an LGU ordinance, fee eligibility, or payment calendar. BPLO and the
City Treasurer must approve the configuration before enabling quarterly payments.
Existing automatic upload/re-upload scans and manual admin scan buttons remain.

## Upgrade an existing installation

1. Back up the database, all uploads, and private deployment configuration. Verify
   the backup and perform a restore drill in a separate database. Stop Apache,
   scheduled workers, and other database writers during the upgrade.
2. Deploy the updated files, retaining `.env`, any legacy `config.local.php`, and `storage/uploads`.
   PHP 8.0+, PDO MySQL, fileinfo, mbstring, curl, and Composer dependencies are
   required; encrypted backup/restore also requires ZIP with AES support.
3. Confirm migrations through `008_operations.sql` are installed. In phpMyAdmin,
   select the existing application database and import
   `database/migrations/009_permit_terms_installments.sql` **once**. Do not import
   the main schema or an example database dump over existing data. MySQL DDL is
   not fully transactional: if an import stops partway, keep the site offline and
   inspect/restore the backup rather than blindly rerunning the migration.
4. A fresh, empty installation imports the updated `database/schema.sql` instead.
   It already contains migration 009; do not apply 009 again. Successful upgrades
   have `applications.permit_effective_date`, `payments.plan_snapshot`, and the
   `permit_payment_policy`, `payment_installments`, `payment_evidence`, and
   `payment_reference_claims` tables.
5. Restart Apache. As an administrator, open **Fee settings**. Confirm the fee
   formula, then save **Permit validity and installment policy** using the LGU's
   approved policy reference and rules described below. New date confirmations
   require a policy reference. Quarterly payment and early release start disabled.
6. Confirm the application timezone (`APP_TIMEZONE`, default `Asia/Manila`) and
   reachable `APP_URL`. Apache and CLI must share database, timezone and SMTP
   configuration. Restart the existing scheduled worker after configuration.
7. Exercise a synthetic annual and quarterly renewal in staging: preview,
   approval/date confirmation, evidence, verification, release, receipt, dashboard,
   and QR page. Compare stored balances and dates before allowing production use.

No live database migration is performed by copying these files or running the
isolated tests. Until 009 is installed, the existing payment workflow is retained.

### Preserved records

The migration does not delete applications, payment parents, receipts, files,
document versions, scan history, or QR tokens. Each old payment becomes one
`Annual (legacy)` installment carrying the same amount, status, receipt, and proof.
Previously submitted evidence is retained as its first history entry. Legacy
receipt URLs remain available; new receipts address an individual installment.

Already released permits retain their existing QR expiration, or the previous
December 31 rule when no QR record exists. Their effective date is backfilled from
the existing issue/release history (with historical timestamps as fallbacks) and
marked `legacy`. They are not silently extended to a new anniversary. Unreleased
applications require BPLO date confirmation before release. Existing assessments
are not converted into quarters or re-priced. Missing historical files cannot be
recovered by a migration; a backup fails if referenced files are missing.

Rollback requires restoring the consistent pre-upgrade database/uploads and matching
code during maintenance. Do not drop the new tables after collecting installment
evidence: that would lose financial history.

## Configure the approved LGU rules

Only administrators can change fee and payment policy. Treasurer accounts can
verify/reject payments and view financial reports, but cannot approve applications,
change permit dates, enable quarterly payment, or change fees.

- **Policy reference:** identify the approved ordinance/policy used by BPLO.
- **Validity rule:** `one_year` validates an inclusive anniversary term. For
  example, April 15, 2026 through April 14, 2027; February 29, 2024 through February
  28, 2025. `bplo_dates` permits BPLO to enter dates according to another approved
  local date rule. Both dates and the confirming staff/time/reference are stored.
- **Quarterly eligibility:** choose the individual assessed fee components allowed
  in four installments. Every unselected fee stays in a separate upfront obligation.
  Enabling quarterly requires eligible fees and four strictly increasing deadlines.
- **Deadline mode:** month offsets are relative to the confirmed effective month;
  day `0` follows its day of month, clamped to month-end. Alternatively, enter four
  calendar `MM-DD` deadlines and year offsets from the effective year. Calendar
  February 29 is disallowed for a recurring schedule. Dates are validated again
  against the actual permit term during preview/approval. A deadline already past
  is immediately due; the system does not shift it to a later year automatically.
- **Annual/upfront deadlines:** signed day offsets from the effective date. These
  obligations must be verified before release even if their deadline is later.
- **Installment-based release:** explicitly enable only when authorized. Choose
  the first 1–4 quarters required before release. Upfront fees are always required;
  any additional installment due on or before release is also required. Leaving
  early release disabled requires the entire assessment to be verified.
- **Reminder windows:** configure days before installment due dates and permit
  expiration. `0` means start on the due/expiration date, not disable reminders.

Amounts are converted to integer centavos. The first three quarterly installments
use whole-cent division; the fourth contains the rounding remainder, so the four
quarters plus upfront fees equal the annual assessment exactly. Quarterly-eligible
totals below four centavos are refused. No interest, penalties, discounts, or
automatic payment processing are introduced by this update.

Submitted renewal quotes and approved payment plans retain their fee/policy
snapshots. Policy changes apply to new quotes/plans, not existing obligations.
Changing policy after a preview requires the applicant to preview again before
submission. Once an assessment exists, its amounts/schedule and confirmed term
are immutable through normal review actions. Corrections to existing assessed or
released records require a separately authorized reconciliation process; do not
edit the database to roll an obligation forward or overwrite receipts.

## Applicant and staff workflow

1. On renewal, enter gross sales/inspection requirements, choose the required
   **Annual** or **Quarterly** frequency, and propose an effective date. Preview
   before selecting document files, because preview is a server round trip.
2. Review the annual assessment, eligible/upfront split, due-now/release-required
   amount, each installment/date, and balance remaining after those amounts are
   verified. Select documents and submit within 30 minutes. Financial/date changes
   require a fresh preview. The server recomputes the quote; posted totals are not
   trusted. Duplicate submissions using the same quote cannot create another
   application. Uploads still trigger automatic document scanning.
3. BPLO reviews and confirms the effective/expiration dates under the configured
   policy. Approval creates the assessment and schedule once, anchored to the
   confirmed effective date. Proposed dates are not final; applicants see final
   dates and obligations on the application/payment pages after approval.
4. The applicant submits a reference, payer, authorized method, and payment proof
   for each obligation separately. Online methods require PDF, JPEG, or PNG proof
   up to 5 MB; the counter method records a treasury reference. This portal records
   evidence, not a transfer of money. Pending evidence is not verified payment.
5. BPLO or an active Treasurer verifies or rejects the specific evidence version.
   Rejection requires a correction reason and allows resubmission. Old evidence
   remains available to authorized staff. Stale forms, repeated submission tokens,
   duplicate verification and reuse of a method/reference on another installment
   are blocked. Each verified obligation gets its own receipt.
6. Annual release requires full verified payment. Quarterly release obeys the
   snapshotted release rule. The annual parent remains **Partially Paid** until
   every amount is verified; a permit may be **Released** while its payment plan
   is **Partially Paid**. Later installments can be paid after release and never
   extend or shorten the stored term. Expired terms cannot be newly released.

Dashboard, application, certificate, and public QR use the stored permit dates.
Issue date is distinct from effective date. A future term is **Not yet effective**;
the public page reads current dates even if a QR cache entry is older. The existing
token remains stable. Public QR output excludes applicant, proof and balance data.
Unpaid overdue installments are flags for staff review, not automatic revocation.

## Reminders, delivery, and reports

Schedule the existing `php cli/operations.php run` worker every minute as described
in [OPERATIONS.md](OPERATIONS.md). It generates installment and expiration events,
then sends through the existing outbox. Upcoming payments/expiration notify the
registered applicant; overdue installments also notify active BPLO/Treasurer staff.
Submitted but unverified evidence remains outstanding and can be flagged overdue.
Expiration reminders are suppressed when a newer active renewal already exists.

Each installment/deadline or permit/expiration has distinct upcoming and overdue/
expired event keys. Repeated worker runs do not create duplicate jobs. These are
once-per-phase alerts, not daily repeat messages. Existing delivery logs, recipient
authorization checks, Message-ID stability and retries are reused. Monitor **Email
deliveries**. SMTP acceptance is not a guarantee of Gmail inbox placement; check
provider bounces/spam filtering as well. Do not run production workers in tests.

Financial reports are available to BPLO and Treasurer staff:

- **Collections:** verified child payments in the selected payment-date range,
  with legacy-parent fallback only when no child exists; never parent plus child.
- **Outstanding:** current balances for assessments created in the selected range,
  not a historical as-of reconstruction. Paid amounts are aggregated before joins.
- **Overdue:** currently unverified obligations whose due dates lie in the selected
  range and precede today in the application timezone; permit status stays separate.

Backup includes current and historical installment proofs as well as existing
document history and all tables. Access to evidence and receipts is restricted to
the owner and authorized financial staff.

## Verification

Run from the project directory with the local database server available:

```text
php tests/permit_installments_test.php
php tests/reupload_document_http_test.php
php tests/operations_isolated_test.php
php tests/layer_separation_test.php
```

The first three create random disposable databases using the configured connection
credentials, never the configured live database. They require CREATE/DROP DATABASE
privileges; the HTTP tests also require available local listening ports and process
execution. They use synthetic uploads and a fake mail transport/local fake AI,
not real applicant files, paid AI requests, or real email. Temporary fixture
databases are removed afterward. The recovery test requires AES-capable PHP ZIP.

Coverage includes migration preservation, annual and quarterly flows, rounding,
duplicate evidence/verification/application submission, ownership/roles/CSRF,
rejection history, release controls, later payments, leap dates, consistent
dashboard/certificate/QR validity, reminders/deduplication, non-double-counting
reports, and encrypted backup/restore of payment history. Run the existing OTP,
document-email, automatic-scan, Treasurer, certificate, and motion tests as well.
