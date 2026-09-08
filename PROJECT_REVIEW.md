# Project review

Review date: 2026-09-08

The highest risks identified are around billing accuracy and deployment. The review was read-only; no application code was changed.

## Resolution status

Implemented on 2026-09-08:

- Bootstrap seeding now creates the owner account only when it does not exist.
- Invoice suggestions separate identical descriptions by project and hourly rate.
- Rates retain centime precision across project, invoice, estimate, and recurring-invoice forms.
- Recurring occurrences have a database uniqueness constraint, generation locks and rechecks the schedule, and failed auto-sends are recorded and retried against the existing invoice.
- Month-end anchors survive resume and unrelated schedule edits.
- Web and MCP estimate mutations share validation rules, including client/project ownership, numeric bounds, and text limits.
- Backups include invoice and estimate PDFs, verify their artifacts before recording success, prune by configurable retention, and can mirror to S3-compatible off-server storage.
- Invoice entry selection and suggested lines now reconcile in both directions.
- Regression coverage was added for these cases, and the README's PHP/Laravel versions now match Composer.

Deployment still needs `BACKUP_MIRROR_DISK` and the corresponding storage credentials to enable off-server copies. Automated verification checks backup structure and readability; a periodic restore into an isolated database remains an operational drill.

Implementation verification:

- 522 tests passed (2,557 assertions), including database-backed billing, retry, uniqueness, validation, and backup regressions.
- Laravel Pint and `git diff --check` passed.
- The production Vite build passed (873 modules transformed).
- Composer validation and audit passed with no remaining dependency advisories after updating patched transitive versions.
- Browser QA, a simultaneous multi-process concurrency test, live S3 mirroring, an isolated restore drill, and production deployment checks were not run.

## Issues

### 1. High — Deployment resets the owner's password and preferences

Every deployment runs `BootstrapSeeder`, which overwrites the existing user's password from the environment and resets their settings. Password changes made through the app therefore disappear after deployment.

**Recommendation:** Make account initialization create-only.

References: [BootstrapSeeder.php](database/seeders/BootstrapSeeder.php), line 19; [deploy.sh](deploy/forge/deploy.sh), line 19.

### 2. High — Invoice suggestions can use the wrong hourly rate

Entries are grouped solely by description, then charged at the first project's rate. A lightweight reproduction confirmed that two one-hour entries at CHF 100 and CHF 200 become **CHF 200 instead of CHF 300**.

**Recommendation:** Group by project/rate as well as description.

Reference: [InvoiceBuilder.php](app/Services/Invoicing/InvoiceBuilder.php), line 52.

### 3. Medium — Fractional hourly rates are silently rounded

Invoice creation and recurring-schedule editing round rates to whole francs. A stored CHF 145.50 rate becomes CHF 146 and can be saved back at that value.

**Recommendation:** Preserve decimal precision throughout these forms.

References: [InvoiceController.php](app/Http/Controllers/InvoiceController.php), line 117; [RecurringInvoiceController.php](app/Http/Controllers/RecurringInvoiceController.php), line 98.

### 4. Medium — Failed automatic sending can strand invoices

Recurring generation advances the schedule before sending, but only catches `DomainException`. SMTP or PDF failures can abort the remaining schedules and leave the generated draft without an automatic retry.

**Recommendation:** Record delivery failures separately and retry the existing invoice.

Reference: [RecurringInvoiceGenerator.php](app/Services/Invoicing/RecurringInvoiceGenerator.php), line 62.

### 5. Medium — Overlapping recurring runs can create duplicate invoices

Generation doesn't lock and recheck the schedule or enforce uniqueness for a billing occurrence. A scheduled run overlapping "Run now" can generate the same period twice, potentially emailing both invoices.

This is a code-level concurrency risk; no concurrency test was run.

**Recommendation:** Lock and recheck the schedule inside the transaction and enforce uniqueness for each billing occurrence.

Reference: [RecurringInvoiceGenerator.php](app/Services/Invoicing/RecurringInvoiceGenerator.php), line 45.

### 6. Medium — Month-end recurring schedules can shift dates

Resume derives the billing anchor from the already-clamped next-run date. A lightweight reproduction confirmed that a schedule anchored on the 31st resumes on **March 28 instead of March 31** after February. Editing also overwrites the anchor from that date.

**Recommendation:** Preserve the original anchor unless explicitly changed.

References: [BillingPeriod.php](app/Support/BillingPeriod.php), line 40; [RecurringInvoiceController.php](app/Http/Controllers/RecurringInvoiceController.php), line 121.

### 7. Medium — MCP estimate creation bypasses important validation

The MCP tool accepts a project without checking that it belongs to the selected client. Numeric and length validation is also weaker than the web forms.

**Recommendation:** Share validation between web and MCP entry points.

Reference: [CreateEstimate.php](app/Mcp/Tools/CreateEstimate.php), line 45.

### 8. Medium — Backups omit generated estimate PDFs

The backup archives only the invoice directory, although sent estimates store PDFs separately. Restoring these backups would recover estimate records without their original attachments.

**Recommendation:** Include both document directories.

Reference: [BackupCommand.php](app/Console/Commands/BackupCommand.php), line 96.

## Other improvements

- **Reconcile invoice selections and lines.** Unchecking an entry leaves its charge in the invoice; removing a line can still mark its entries billed. Both behaviors were reproduced. Even if independent editing is intentional, the form needs clearer reconciliation before saving. Reference: [Create.vue](resources/js/Pages/Invoices/Create.vue), line 44.
- **Improve backup resilience.** Add off-server backup storage, retention, and a restore check; these aren't implemented in the repository.
- **Expand regression coverage and update documentation.** Add coverage for the cases above and update the README's PHP/Laravel versions, which differ from Composer's requirements.

## Verification and limits

- 21 unit tests passed (45 assertions).
- 200 PHP files passed syntax checks.
- 67 Vue components passed script/template compilation checks.
- Lightweight reproductions confirmed mixed-rate invoice grouping, invoice selection/line divergence, and month-end recurring resume behavior.
- Database-backed feature tests, a concurrency test, a full build, browser QA, and production checks were not run.
