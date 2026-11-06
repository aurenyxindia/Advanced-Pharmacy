# Advanced Pharmacy — Staff Android App

Native **Jetpack Compose** mobile client for the Advanced Pharmacy ERP. It uses the **same PHP API and MySQL backend** as the Angular web app (`https://pp.gmpserver.com/api` by default). No duplicate business logic on device.

## Open in Android Studio

1. Open folder `advanced-pharmacy-android` (not the repo root).
2. Sync Gradle, run on device/emulator (min SDK 26).

**Kotlin note:** The app id starts with `in.` (India TLD). Kotlin treats `in` as a keyword, so every `.kt` file uses `` `in`.paperlesspharmacy.advanced… `` in `package` / `import` lines. If you add new Kotlin files, run `tools/fix-kotlin-in-package.ps1` or escape `in` manually.

## API base URL

Default: `https://pp.gmpserver.com/api`

Override in `local.properties`:

```properties
ADV_PHARMA_API_BASE=https://192.168.1.108/Advanced%20Pharmacy/pharmacy-api/public/api
```

Use your LAN IP if testing against local XAMPP (ensure phone and PC share Wi‑Fi).

## Architecture

| Layer | Purpose |
|--------|---------|
| `data/ApiClient` | OkHttp + JWT refresh, same envelope as web (`success`, `data`, `message`) |
| `data/UserRightsRepository` | `/user-rights/me` — nav matches web matrix |
| `data/master/*` | Master config from `assets/masters_config.json` (exported from web) |
| `data/transaction/*` | Purchase & sales repositories (list, CRUD, search, payment mode, WhatsApp) |
| `ui/screens/*` | Module UIs (compact typography, small controls) |
| `ui/AppShell` | Drawer nav mirroring `main-layout.ts` |

## Branding

Teal primary `#0D9488`, slate text, compact 11–13sp body — aligned with web `card-pharma` / KPI styling.

## Module coverage (v1 scaffold)

Implemented with live API wiring:

- **Launch splash** → **Login** → **Register** (3-day free trial via `clients.trial_ends_at`, `access_channel=android`, auto `platform_active`). Trial banner + **Buy / renew plan** in drawer. When trial ends (`subscription.needs_payment`) → plans paywall → confirm UPI ref → `POST /subscription/activate` (sets `subscription_status=active`, `android_expiry_date`). Existing web/`both` users are never blocked by trial. **Forgot password** (OTP), session, rights-aware navigation
- **Dashboard** (`HOME`, web parity) — `GET dashboard/summary` + `GET min-inventory/alerts`. Quick actions (New Sale / Reports / Min Stock / Auto PO by rights). Financial KPI tiles (today/month sales, purchase, profit, outstanding). Expiry buckets + product/low-stock/customer meta. Min-inventory alert list with View All sheet (search, edit min qty, Generate Order → Auto PO), Set Min / Auto PO / Short-stock report. Expired stock loss periods + all-expired. Profit & discount (month / 3-month, OTC vs Rx, by category, monthly dual-bar chart). HO store cards. Sales & purchase 14-day dual-bar trend. Distributor purchase + category + top products H-bars. Recent sales list. Refresh + empty/error states.
- **Masters (full module)** — config synced from web `master.config.ts` via `tools/export-masters-config.mjs` → `assets/masters_config.json`. All 15 master definitions: list columns, search, pagination, stats, list filters (schedule / generic category), product **bind**, rate margin **COMMON** row, create/edit with **all fields**, `showWhen`, validations, option sources (`meta/*`, generics, manufacturers, pack sizes, GST states). Home tiles match web `masters-home.ts`. Extra configs (payments, credit/debit notes) available for future nav wiring.
- **Sales** — bill log (search, retail/wholesale/IPD filter, KPIs), view bill, **payment mode** patch, WhatsApp; **billing** (customer lookup, doctor, narration, bill discount, scan/search products, batches, save `POST /sales`). **Counters** (Counter 1–3, Cash Counter, IPD, + add/remove) each keep their own patient and bill; Cash Counter = walk-in Cash/Counter, Schedule H/X hidden, H1 can be added then sent to Counter 1–3 from a **COUNTER** dropdown on that product row (bill stays on Cash Counter). **Round-off** to nearest rupee with "Round off" line. **Cash+UPI** split (enter Cash, UPI = Net − Cash) on billing and the log's payment-mode sheet (`PATCH /sales/{id}/payment-mode` with `cash_amount`). Full Edit Bill = admins only; staff with SALES edit = payment mode only. **Wi‑Fi A4 print**: Print / Dual Print download the API invoice PDF and open Android’s system print dialog (`util/PdfWifiPrint.kt`); Dot Matrix still opens HTML preview.
- **Purchase** — log (month, search, KPIs, view, edit, delete); **entry** (supplier search, cash/credit, due date, bill/CD/transport, product search, line items, create/update)
- **Purchase Order** (`PURCHASE_ORDER`, web parity) — log with KPIs (orders / customise / auto / products), debounced search, From–To dates, type filter + reset; view sheet (items, PDF, share PDF, edit); Customise / Edit PO for any type (paged distributor picker, PO date + type, product search ≥2 letters with facts panel, qty merge, editable qty, remove, discard confirm); Auto-Purchase short book (month-wise sold grid, available pill, last distributor, 7/15/30/60-day req qty = round(sold ÷ (months × 30) × days), select all visible, Make PO → one group per distributor resolved against master, per-group save, saved rows removed). Create/edit/auto need Edit right; adaptive grid on tablets.
- **Import Bill** — five sources matching web: **Upload PO** (13 retail ERP CSV formats parsed on device, distributor first), **Scan & Bill** (PDF/photo/camera → `import/purchase/ai`), **Import by AI** (keeps server matches), **CSV / Excel** (`import/purchase/parse`; `.xlsx` converted on device), **From Email** (Gmail OAuth in browser → `import/gmail/*`). Verify step: import report + CSV share, invoice no duplicate check, distributor, cash/credit, CD % / amount, debit note, checklist, filter OK/Gap, Match with master, editable 23-column grid (pack, batch, expiry, qty, free, MRP, rate, PTR/PTS auto, disc, GST), Add to Product Master, PTR/PTS calculator, save `POST /purchase` (`IMPORT_BILL` / `PURCHASE` edit)
- **Opening Stock** (`OPENING_STOCK` / `PURCHASE` edit, web parity) — three tabs. *Opening Stock*: Excel template download (.xlsx), upload .xlsx/.csv (required columns check, expiry formats incl. Excel serial), fuzzy master matching with unmatched filter + match sheet, add-to-Product-Master in place, manual entry (P.No/product search, batch, MM/YY expiry with −1y…+10y range, packs, MRP, rate, GST, HSN, shelf, taxable/amount), edit/remove lines, bulk save with ref toast, write-off expired, saved batches with KPIs + edit (PUT) / delete. *Stock Adjustment*: type, reason (quick picks, 255 max), batch search, units↔packs physical count, diff colouring, per-row reason, changed-only, save + audit log report. *Minimum Inventory*: product search, min qty (default 15), low-stock list, edit/reset.
- **AuraGharTak** (`AURA_GHAR_TAK`, web parity) — KPIs (new / ready / out / delivered), Orders + Share stock catalogue tabs, status chips with counts, filter, 45 s refresh, customer-cancel policy (Yes/No for Edit, badge for View). Order sheet: patient + call, address, note, prescription (image zoom viewer / PDF open), items + stock, bill info, rider assign (`agt/riders`, `agt/orders/rider`), Do sale / Cancel / Out for delivery (WhatsApp or not) / Mark delivered with web confirm texts + WhatsApp result. Share: existing patient search or new customer → Send on WhatsApp / Link only, copy + share link. Global new-order popup (5 s poll, admin / pharmacist / cashier) with Rx, payment QR, Open order, Do Sale. Sales billing: Do Sale handoff to a free counter, pending-order banner after phone match, Add to bill from fulfilment segments, Rx viewer, `agt_order_id` on save (server marks Ready). Purchase Order log “To” date defaults to today.
- **IPD / Admissions** (`IPD`, web parity) — KPIs (admitted, billed, paid, outstanding), Admissions tab (Admitted / Discharged / All chips with counts, search), admission sheet (totals, call, bills → invoice PDF, payments, Add Bill, Payment with Full + mode, Discharge with balance toast), Admit dialog (ward ≤60, bed ≤30). IPD Billing tab (needs Edit): contact lookup, patient select or new name, hospital / doctor / ward / bed / relative, generic filter + product search, batch / qty / disc, editable lines, totals, prior balance, optional cash paid → `POST sales` (IPD, credit, `ipd_admission_id`).
- **Refill Reminders** (`REFILLS`) — Overdue / Due Today / Next 7 days KPI filters (`refills/summary`), status chips, search, Call, WhatsApp (marks Notified), Mark Notified / Completed / Cancel, New reminder (days supply 1–365 or date, notes ≤255).
- **Deliveries** (`DELIVERIES`) — KPIs (orders, active, out for delivery, est. value), debounced search, status chips with counts, order sheet (call / WhatsApp, items, status, rider, note, Cancel & return stock with confirm); edit needs Edit right.
- **Return Note** (`RETURN_NOTE`, web parity) — four tabs. *Debit Note*: KPIs (notes / open / open value / cleared), search, status filter, note sheet (lines, totals, applied PO), PDF print, edit while Open; form: product search → batch (stock) → distributor from purchase history (invoice, PO, rate, GST), return qty ≤ stock, reasons, CGST+SGST vs IGST by state, lines + totals, `POST/PUT debit-notes/return`. *Retail Sales Return*: latest 30 bills + search, per-line return qty ≤ max (tabs/units), discount-aware amounts, round-off, return total capped at bill remaining, submit → PDF opens; History tab with detail sheet + print. *Credit Note*: wholesale customer → products → batches (sold qty, rate, auto deduction %), editable Ded %, same tax split, edit while Open, PDF. *Deduction %*: months-before-expiry slots list + add. Create/edit/submit need Edit right.
- **Daily Closing** (`DAILY_CLOSING`, web parity) — date picker, Closed badge, KPIs (net sales, total cash, customers, cash difference), Sales / Customer Payment / Cash Adjustment / Debited Payment cards, available cash prefilled with total cash, Complete Day End with confirm (sends WhatsApp `daily_closing` server-side; result shown like web), Export Day + range report (load, totals, expandable rows, per-day download, Export Excel) saved to Downloads with tap-to-open notification.
- **Accounts** (`MASTERS` / `PAYMENTS`, web parity) — hub tabs Chart of Accounts + Payments. Ledgers: full Accounts Master CRUD (types asset/liability/income/expense/equity/customer/supplier/bank/cash, bank fields when type=bank, IFSC, opening balance). Payments: Distributor dues (search, FIFO amount or per-bill pay, mode, txn, settle); Retail / Wholesale customer dues (business_type gated like web), amount FIFO, history with print receipt (HTML); Vouchers create/search/delete. Accounts and Payments drawer entries open the same hub (Payments starts on Payments tab).
- **Head Office** (`ho_admin`) — Performance (KPI totals + per-branch sales/purchase/profit/stock/SKUs, HO badge, search); Portfolio (products across branches, stock, schedule); Stock Transfer (from/to branch, stock picker, qty capped to available, recent transfers + transfer log sheet); Branches tab = Branch Master CRUD (code, GSTIN, phone, status, address).
- MR
- **Support** (web Support Center parity) — KPIs (tickets / open / resolved / rejected), status chips, admin client search + limited recent log, New Ticket (subject, query, up to 8 screenshots), ticket detail with query / support remark / client & admin attachments, admin Support Action (status, remark, screenshots/files, lock when Resolved), live chat with 5 s polling, reply reopen of Resolved tickets, attachment download + image preview, global 15 s poll popup (new ticket / status / reply → Open / Reply) via `support/*` APIs
- **My Profile** (`MY_PROFILE`, web parity) — Profile tab: hero (photo), locked registration fields (`contact_person`, address, GST/PAN/FSSAI/DL), editable email / DOB (date picker) / bank / IFSC with validation, admin **Hide Master Catalogue** (Product + Distributor checkboxes → `hide_master_products` / `hide_master_distributors` on `PUT /profile`; masters tabs stay open for own adds), **Save** via `PUT /profile`, five document uploads (stamp, logo, scanner, photo, DL) via `POST /profile/upload/{type}` with image preview. Admin **User Rights** tab: staff list (search, edit, deactivate), create/edit form (name, reg, phone, email, password, role, branch, joining date, address), module matrix View/Edit/Delete with All View/Edit/Delete/Clear and RETURN_NOTE helper, `GET/POST/DELETE /user-rights/*` + `meta/branches`. Sidebar close (X) on drawer header.
- **Reports** (`ALL_REPORTS`, web parity) — catalog + columns synced from web `reports.ts` via `node tools/export-reports-config.mjs` → `assets/reports_config.json` (63 reports, summary + detail columns). Hub: search, category chips with counts (All / GST / Purchase / Sales / Stock / Accounts), per-report View + Excel (defaults: month start → today). Runner: HO branch, From/To (no future, To ≥ From), Month (GSTR-1 / HSN portal), As-on date, near-expiry months 3/6/9/12, Margin %, searchable entity filter (`reports/filter-options`, Opening Stock badge, optional “All” = summary, selection switches to detail columns), auto-run on open, results search, records / Net / GST / Profit chips, Stock items + value, GST Summary KPI cards (net payable red/green), Profit total + top products, formatted cells (₹ en-IN, dd-MM-yyyy, yyyy-MM expiry, audit badges), totals row (rates / MRP / % excluded), row detail sheet, **Excel download** — same styled .xlsx as web `ReportExcelService` (letterhead, navy title, multicolour headers, borders, zebra rows, #,##0.00, totals row, frozen header) saved to Downloads with Open action and a tappable “Report downloaded” notification that opens the file (`util/ReportExcelWriter.kt`, `util/DownloadNotifier.kt`) — plus **A4 landscape PDF Wi‑Fi print** (system print dialog via `PdfWifiPrint`, letterhead retained). Opening Stock “audit log” deep-links to the Stock Adjustment Audit report.

**Next increments** (same app, no backend changes): sales edit/PDF, return-note wizards, AuraAI chat — port from Angular using existing endpoints in `pharmacy-api/src/routes/api.php`.

## Related apps

- `auraghartak-android` — **customer** AuraGharTak shop (public AGT APIs)
- This project — **staff** ERP for pharmacists, cashiers, purchase, admins

## Build release

Signing is read from `local.properties` (not committed):

```properties
RELEASE_STORE_FILE=C:\\AndroidKeys\\AdvancedPharmacy-release.jks
RELEASE_STORE_PASSWORD=...
RELEASE_KEY_ALIAS=key0
RELEASE_KEY_PASSWORD=...
```

Local credentials copy (outside the repo): `C:\AndroidKeys\AdvancedPharmacy-release.credentials.txt`

The older keystore `C:\AndroidKeys\AdvancedPharmacy` is left untouched — use it only if you still know its password.

```bat
cd advanced-pharmacy-android
gradlew.bat assembleRelease
```

APK: `app/build/outputs/apk/release/`

Or in Android Studio: **Build → Generate Signed App Bundle / APK** → pick `AdvancedPharmacy-release.jks`, alias `key0`, password from the credentials file above.
