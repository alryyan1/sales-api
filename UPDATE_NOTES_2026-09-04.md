# Update Notes — 2026-09-04

Branch: `auger-lifecare` (frontend `sales-ui` + backend `sales-api`)

This release adds **Scheduled Sales with WhatsApp invoice delivery**, reworks the
top navigation, and improves the Sales Report (shift-scoped users, refunds
breakdown, auto-refresh).

---

## 1. Scheduled Sales (new feature)

Create a sale now, have the system turn it into a real invoice automatically at a
future date/time and notify the customer over WhatsApp.

### Backend (`sales-api`)

**Data model**
- `create_scheduled_sales_table` migration — `scheduled_sales` with client,
  warehouse, user, `scheduled_at`, `status`
  (`pending` / `processing` / `completed` / `failed` / `cancelled`), optional
  discount (`discount_amount` + `discount_type`), notes, and the executed
  `sale_id` once created. Tracks per-leg WhatsApp state for both the customer and
  the owner copy (`whatsapp_customer_*`, `whatsapp_owner_*`). Indexed on
  `(status, scheduled_at)`.
- `create_scheduled_sale_items_table` migration — `scheduled_sale_items`
  (product, quantity, unit price).
- `add_source_to_sales_table` migration — new `sales.source` column
  (`pos` default, or `scheduled`), indexed, so scheduled-origin invoices are
  distinguishable from POS sales.
- Models `ScheduledSale`, `ScheduledSaleItem` with status constants and relations.

**API** — `ScheduledSaleController` (`/scheduled-sales`)
- `index` — paginated list with `status`, `client_id`, `scheduled_from/to`,
  `search` (by client name) filters.
- `store` / `update` — validates items and schedule; `update` only allowed while
  `pending`. Applying a discount requires the `تخفيض` permission.
- `cancel` — allowed from `pending` or `failed`.
- `retry` — re-queues a `failed` row that has not yet produced a sale.
- `resendWhatsapp` — re-sends the invoice message for an already-executed row.
- All mutating actions require the new `جدولة الفواتير` permission.
- `ScheduledSaleResource` / `ScheduledSaleItemResource` for API output.

**Execution pipeline**
- `SaleCreationService` (new, shared) — extracted sale-creation logic (stock
  check, item creation, cost-price resolution, discount math) so the POS endpoint
  and the scheduler use the exact same path. Deliberately excludes payments.
- `ScheduledSaleExecutor` — executes one due row: creates the real `Sale`
  (unpaid, `source = scheduled`, attached to the latest shift), links `sale_id`,
  then triggers the WhatsApp notification. Only a failed **customer** WhatsApp leg
  fails the row; the owner copy is best-effort.
- `ScheduledSaleNotifier` — sends the Meta-approved WhatsApp template to the
  customer phone (from the Client) and to the configured owner phone
  (`scheduled_invoice_owner_phone` setting). Template name/language come from
  settings (`scheduled_invoice_template_name`, `scheduled_invoice_template_lang`).
  Body params: customer name, invoice no., date, total, due. Includes the
  `DOWNLOAD_INVOICE|sale_id:<id>` quick-reply payload consumed by the existing
  WhatsApp webhook to send back the invoice PDF.
- `RunScheduledSales` command — `php artisan scheduled-sales:run [--dry-run]`.
  Atomically claims each due row (`pending` → `processing` with `lockForUpdate`)
  so concurrent runs don't double-execute, recovers rows stuck in `processing`
  for >15 min (marks them `failed`), and prints a succeeded/failed summary.
- `run-scheduler.bat` — Windows wrapper that runs `artisan schedule:run`, for
  registering as a scheduled task on the server.
- `ScheduledSalesPermissionsSeeder` — creates the `جدولة الفواتير` permission and
  grants it to the `ادمن` / `admin` roles.

> **Deploy steps:** run the three new migrations, run
> `ScheduledSalesPermissionsSeeder`, configure the `scheduled_invoice_*`
> settings, and schedule `scheduled-sales:run` (via `run-scheduler.bat` / cron
> every minute).

### Frontend (`sales-ui`)

- `services/scheduledSaleService.ts` — typed client for the full
  `/scheduled-sales` API (list/get/create/update/cancel/retry/resend-whatsapp).
- `pages/sales/ScheduledSalesListPage.tsx` — filterable list with status badges,
  WhatsApp delivery state, and row actions (edit / cancel / retry / resend).
- `pages/sales/ScheduledSaleFormPage.tsx` — create / edit form (client, schedule
  datetime, line items, discount, notes).
- `lib/scheduledSaleCart.ts` — helper to hand off the current POS cart into a new
  scheduled sale.
- `locales/{ar,en}/scheduledSales.json` — translations.

---

## 2. Navigation rework

- **Reports** and **Administration** were removed from the sidebar and are now
  sub-menus in the **user menu** (top-right), next to Settings.
- `navItems.ts` — split into `navItems` (sidebar), `reportNavItems`, and
  `adminNavItems` (flat lists, no nested `children`).
- `UserMenu.tsx` — the avatar trigger is now a name + chevron button. Adds
  "Reports" and "Admin" expandable sub-menus, filtered by the user's
  `allowed_navs`. Expiry-only reports (moved/expired, low-stock) are hidden when
  the `hide_expiry_date` setting is on. The Profile link was removed.
- `NavigationPermissionsSection.tsx` (admin → user permissions) — now also lists
  the report and admin routes so they remain assignable per user.
- `TopAppBar.tsx` — adds a **search-invoice-by-number** input on the POS page;
  the result is broadcast via a `pos-select-sale` event.

---

## 3. Sales Report improvements

### Backend
- `PaymentController@stats` — response now also returns `returns_total` and
  `returns_by_method` (sale refunds over the same window, broken down by the
  payment method the refund was issued through).
- `UserController@listForFilters` — accepts an optional `shift_id`; when given,
  only returns users who actually recorded a payment in that shift.

### Frontend
- `ReportFilters.tsx` — the users dropdown is now fetched inside the component and
  **scoped to the selected shift**; a stale selected user is cleared when the
  shift scope changes (`noUsersForShift` empty-state text). Picking a shift
  disables and clears the start/end date inputs (a shift already defines its
  range). The Shift selector was moved above the Client selector and widened.
- `ReportStats.tsx` — new stat tiles: **Total Refunded** and a per-method
  "Refunded (<method>)" breakdown.
- `PaymentsTable.tsx` — removed the "Reference" column.
- `SalesReportPage.tsx` — report data auto-refetches when the browser tab regains
  focus (`sales-report`, `payments`, `payment-stats`, `expenses-report` queries).
- `paymentService.ts` — `PaymentStats` type extended with `returns_total` /
  `returns_by_method`.
- `locales/{ar,en}/reports.json` — `noUsersForShift`, `totalReturns`,
  `returnViaMethod` keys.

---

## 4. POS & Sales UI

- `components/pos/PosHeaderProductSearch.tsx` (new) — the POS header product
  search autocomplete extracted from `PosBlankPage` into its own component
  (~260 lines removed from the page). Behaviour unchanged.
- `PosBlankPage.tsx` — uses the new component; sale-by-id search moved to
  `TopAppBar` and received back via the `pos-select-sale` event; default payment
  method is now **bankak** instead of cash.
- `SaleSummaryPanel.tsx` — when the current payment method becomes invalid,
  it falls back to **bankak** if available (otherwise the first active method).
- `SaleItemsTable.tsx` — for products with no image but a SKU, show a
  copy-barcode button in place of the thumbnail; product name column widened
  responsively.
- `services/whatsappCloudApiService.ts` (new) — `getSenderNumber()` to fetch the
  Meta sender phone number.

---

## 5. Auth / login

- `LoginPage.tsx` — username and password fields forced to LTR / left-aligned so
  credentials read correctly under an RTL UI.

---

## 6. Config / tooling (backend)

- `config/database.php` — default database name fallback changed
  `auger` → `pharmacy` (env `DB_DATABASE` still overrides).
- `composer.json` / `composer.lock` — added `laravel/boost` (dev).
