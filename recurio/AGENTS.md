# Recurio agent notes — cancellation retention & free-gift email

Companion Pro plugin (sibling directory): `recurio-pro/` next to this `recurio/` folder.

## Cancellation retention flow (Pro)

- **Config & merge:** `recurio-pro/includes/class-cancellation-flow.php` — defaults include `offer_type`, `free_product_ids`, `portal_copy`, coupon expiry.
- **Offer types:** `pause` | `percent_discount` | `fixed_discount` | **`free_product`** (100% single-product coupon after customer picks a SKU).
- **Portal AJAX**
  - `recurio_get_retention_offer` — builds offer JSON (for `free_product`: `products[]` with `id`, `name`, `thumb`, `price_html`; no coupon until accept).
  - `recurio_accept_retention_free_product` — validates subscription + product in allowlist, creates coupon, **sends retention email**, logs `cancellation_survey` with `retained_via: free_product`, `product_id`, `coupon_code`.
  - Constructor in `class-cancellation-flow-portal.php` must register both actions.
- **Logging:** `Recurio_Pro_Cancellation_Flow::log_flow_event()` → event type `cancellation_survey`; `retained_via` may be `pause` | `coupon` | `skip` | `free_product`.

## Free-gift coupon email

- **Why:** WooCommerce does **not** email customers when coupons are created in PHP; we send explicitly.
- **Implementation:** After a successful `grant_free_product_retention_coupon()`, the portal calls `Recurio_Pro_Cancellation_Flow::send_free_product_retention_coupon_email()`, which sends HTML mail via **`Recurio_Pro_Email_Campaigns::send_transactional_html_email()`** (same From/wrapper as other Pro emails: `recurio_settings.emails.fromName` / `fromEmail`). Fallback `_mail_fallback` if campaigns class unavailable.
- **Hooks / filters:** `recurio_pro_send_free_product_retention_email` (bool), `recurio_pro_free_product_retention_email_subject`, `recurio_pro_free_product_retention_email_body_html`, action `recurio_pro_free_product_retention_email_attempt`.

## Dashboard / REST (free plugin repo)

- **Vue:** `assets/vue-dashboard/src/views/CancellationFlow.vue` — offer type includes free product + product multi-search; **`assets/vue-dashboard/src/constants/cancellationFlowPortalCopy.js`** must stay aligned with **`includes/api/class-rest-api.php`** `cancellationFlow.portal_copy` / `free_product_ids` defaults.

## Frontend portal assets (Pro)

- `recurio-pro/assets/js/recurio-pro-cancellation-flow.js` — offer step renders product radios; accept posts to `recurio_accept_retention_free_product`.
- `recurio-pro/assets/css/recurio-pro-cancellation-flow.css` — gift row layout.

When changing retention behavior, sync **PHP portal copy**, **REST defaults**, **Vue constants**, and **localized JS strings** where applicable.

---

## Email notifications — settings UI & customization (Vue + PHP)

Summary of work discussed and implemented: customer-facing email triggers in **Settings → Emails**, Pro template editing, and server-side toggle enforcement.

### Dashboard UX (`assets/vue-dashboard/src/views/Settings.vue`)

- **Rows:** Core + Pro automation lists use compact strips aligned with legacy `.notification-list .notification-item` styling (`#f8fafc`, icon tile `#eff6ff`, title `#1e293b`, single meta line for description + recipients).
- **Actions:** Each row has **Preview** (dialog, sample merge tags) and **Edit** (drawer). Free shows PRO affordance on edit where applicable.
- **Stacking:** Element Plus overlays use `:z-index="EMAIL_UI_OVERLAY_ZINDEX"` (**100050**, above `#wpadminbar`), `modal-class="recurio-email-ui-overlay"`, and `body.recurio-email-editor-active` raises TinyMCE float panels (**100200**).

### Row metadata (`assets/vue-dashboard/src/constants/emailTemplates.js`)

- **`CORE_EMAIL_ROWS` / `PRO_EMAIL_ROWS`:** `toggleProp` maps to `settings.emails.*` booleans. Every core template that sends mail must have a non-null `toggleProp` matching PHP defaults and `Recurio_Email_Notifications` guards.
- **`EMAIL_DEFAULT_BODY_STUBS`:** Object keyed by all 27 template keys (11 core + 16 Pro) containing actual HTML bodies that match PHP's `get_default_template()` output, using `{{variable}}` placeholder syntax. This ensures the editor and preview display real email copy rather than a variable scaffold.
- **`buildDefaultPreviewBodyHtml(meta)`:** Checks `EMAIL_DEFAULT_BODY_STUBS[meta.key]` first; only falls back to the scaffold generator for unknown/future keys.

### Core email toggles (REST + PHP)

- **Defaults** in `includes/api/class-rest-api.php` under `emails`: includes among others `sendCancellation`, `sendSubscriptionPaused`, `sendSubscriptionResumed`, `subscriptionSkipped`, `sendSubscriptionExpired`, `sendTrialReminder` (built-in trial cron — distinct from Pro `sendTrialEnding`).
- **Enforcement** in `includes/core/class-email-notifications.php` via `is_email_trigger_enabled()` at the start of each sender (skipped billing already used `subscriptionSkipped`; cancelled now respects `sendCancellation`).

### Pro HTML body editor (WordPress classic editor, not raw `wp_editor()` PHP)

- **Scripts:** `includes/admin/class-dashboard.php` calls **`wp_enqueue_editor()`** (with existing `wp_enqueue_media()`). SPA uses **`wp.editor.initialize`** / **`wp.editor.remove`** on textarea `#recurio-email-body-wp-editor`.
- **Structure:** Subject stays **per tab** (variant keys). **One shared** body textarea **below** `el-tabs`; syncing on variant change, drawer close, **`saveSettings`** flush, and `onUnmounted`.
- **`tinymce`:** `wpautop: false` for HTML email friendliness; Quicktags + media buttons on.

### Default subject/body in the editor (UX)

- **Display:** Empty stored `customTemplates[key].subject` shows **`EMAIL_DEFAULT_SUBJECT_STUBS[key]`**; empty **body** loads **`getDefaultEmailBodyHtml(key)`** → prefers `EMAIL_DEFAULT_BODY_STUBS[key]`, falls back to `buildDefaultPreviewBodyHtml`.
- **Persistence:** If the user leaves content equivalent to the defaults, store **`''`** so PHP still treats it as “use built-in default.” Uses **two-stage comparison**: (1) primary — compare against `wpEmailEditorInitialContent` (TinyMCE's own reformatted version of the initial load, captured via `init_instance_callback`); (2) fallback — compare against the JS stub string via `normalizeWhitespaceBundle`. This avoids false positives from TinyMCE whitespace/self-closing tag normalisation.
- **`wpEmailEditorInitialContent`:** Module-level `let` in `Settings.vue`; set in `init_instance_callback` on first mount, recaptured on tab switch and re-open; reset to `''` in `teardownWpEmailEditor`.

### Email Branding drawer

A compact summary row (logo thumb + status tags) above the email list opens `emailBrandingDrawerVisible`. The drawer (`el-drawer`, `append-to-body`, `size="50vw"`, `class="email-template-drawer"`) contains:

1. **Header logo** — WordPress media library picker. Empty state: `.email-logo-zone` (dashed upload zone). Filled state: `.email-logo-preview` frame + "Change logo" / "Remove" `el-button` below.
2. **Custom header HTML** — `settings.emails.headerHtml`; overrides the default logo/site-name block entirely when non-empty.
3. **Custom footer HTML** — `settings.emails.footerHtml`; `{{site_name}}` and `{{year}}` replaced server-side in `wrap_email_template()`.

Settings keys added: `emails.headerImageId` (int, default `0`), `emails.headerHtml` (string), `emails.footerHtml` (string). Sanitized via `wp_kses_post()` in `class-rest-api.php`.

**`wrap_email_template()` in `class-email-notifications.php`:** reads `get_option('recurio_settings')` directly (not injected); applies `headerHtml`/`footerHtml` if non-empty, falls back to logo image or site name.

### TinyMCE editor — known gotchas

- **Visual/Code tab:** `switchEditors.go()` fails inside teleported drawers (returns `null` from `tinymce.get(id)`). Custom `onclick` handlers wired in `init_instance_callback` using the `ed` closure reference. See `htmlBtn`/`tmceBtn` block inside `initWpEmailEditor()`.
- **Merge tags button:** `editor.addButton('recurio_merge_tags', {...})` in the TinyMCE `setup` callback. Dropdown is `position:fixed` using `getBoundingClientRect()`. Uses `mousedown + e.preventDefault()` for insert to preserve editor focus.
- **`append-to-body` / scoped CSS:** `el-drawer` with `append-to-body` teleports outside the component DOM; scoped `[data-v-xxx]` selectors never match. All styles for drawer content (`.email-logo-zone`, `.email-logo-preview`, `.recurio-merge-tags-dropdown`, `.email-template-drawer *`) must live in the **global `<style lang="scss">`** block.

### Related files

| Area | Files |
|------|--------|
| Finalize / sanitization | `includes/core/class-email-custom-templates.php`, REST merge/save for `emails.customTemplates` |
| Pro sends | `recurio-pro/includes/emails/class-email-campaigns.php` (uses finalized templates) |
| Email branding (PHP) | `includes/core/class-email-notifications.php` → `wrap_email_template()` |
| Email branding (REST) | `includes/api/class-rest-api.php` → `emails.headerImageId/headerHtml/footerHtml` defaults + `wp_kses_post()` sanitization |

Build Vue from repo root: `npm run build` (`vite.config.js` uses `root: assets/vue-dashboard`).

---

## Subscription Blueprints (free plugin)

Blueprints are **reusable subscription configuration templates** that merchants create once and push
to many products. Unlike the competitor (Sublium) pull model, Recurio uses a **push model**: applying
a Blueprint stamps its settings directly onto each product's post meta; products keep a back-reference
(`_recurio_blueprint_id`) that enables propagation.

### Database

- **Table:** `{prefix}recurio_blueprints`
  - `id`, `name`, `description`, `slug` (unique), `type`, `status`, `settings` (LONGTEXT JSON),
    `created_at`, `updated_at`, `created_by`.
- **Product link:** `_recurio_blueprint_id` post meta on WC products.
- **DB version gating:** `maybe_upgrade_database()` in `recurio.php` runs `upgrade_to_1_0_4_blueprints()`
  when `recurio_db_version < 1.0.4`.

### Blueprint types

| Value | Label | Use case |
|---|---|---|
| `subscribe_save` | Subscribe & Save | Physical goods with recurring discount |
| `recurring` | Recurring Service | SaaS / digital — fixed recurring charge |
| `installment` | Installment Plan | Split product price over N payments |

### Blueprint statuses

- `draft` — created but not yet applied to any product.
- `active` — applied to at least one product (auto-promoted on first apply).
- `archived` — soft-deleted; no longer assignable; existing links preserved.

### Settings JSON fields (sanitised by `sanitize_settings()`)

`billing_period`, `billing_interval`, `trial_days`, `signup_fee`, `signup_fee_type`,
`discount_type`, `discount_value`, `payment_type`, `max_payments`, `subscription_length`,
`allow_one_time_purchase`.

`allow_one_time_purchase` is stored as `'yes'`/`'no'` inside the settings JSON to match
the `_recurio_allow_one_time_purchase` product meta convention. `sanitize_settings()` converts
the incoming boolean from the Vue checkbox; `BlueprintWizard.vue`'s `watch` coerces the string
back to a boolean when loading an existing blueprint.

These map 1-to-1 to existing `_recurio_*` product meta keys via `stamp_settings_on_product()`.

### REST API (`recurio/v1/blueprints`) — `includes/api/class-blueprints-api.php`

| Method | Path | Purpose |
|---|---|---|
| GET | `/blueprints` | List (pagination, search, type/status filter) |
| POST | `/blueprints` | Create |
| GET | `/blueprints/{id}` | Single blueprint |
| PUT | `/blueprints/{id}` | Update — pass `propagate=true` to batch-update all linked products |
| DELETE | `/blueprints/{id}` | Archive (soft delete) |
| POST | `/blueprints/{id}/apply` | Apply to `product_ids[]` array — stamps meta + back-ref |
| GET | `/blueprints/{id}/products` | List products linked via `_recurio_blueprint_id` |
| DELETE | `/blueprints/{id}/products` | Detach a single product (`product_id` param) |
| GET | `/blueprints/{id}/analytics` | LTV, churn rate, revenue from existing subscription tables |
| POST | `/blueprints/{id}/duplicate` | Clone as draft copy |

All routes use `check_permission()` → `manage_woocommerce` capability.

### Propagation

`update_blueprint()` accepts `propagate=true` query param → calls
`propagate_to_linked_products()` → iterates `_recurio_blueprint_id` post meta holders →
calls `stamp_settings_on_product()` on each. This is the key differentiator vs Sublium.

### Analytics endpoint

`get_analytics()` aggregates live data from `recurio_subscriptions` (LTV, churn, counts) and
`recurio_subscription_revenue` (total revenue) filtered by the products linked to the Blueprint.
No separate analytics table is needed — it reuses existing data.

### Product edit page integration

`class-woocommerce-product.php` renders an **"Active Blueprint" notice** at the top of the
Subscription tab when `_recurio_blueprint_id` is set. The badge shows the blueprint name with a
"Manage Blueprints" link. `save_blueprint_meta()` persists the meta on WC product save.

### Vue layer

| File | Role |
|---|---|
| `assets/vue-dashboard/src/views/Blueprints.vue` | Main list page: summary tiles, filter bar, table, detail drawer |
| `assets/vue-dashboard/src/components/BlueprintWizard.vue` | 3-step wizard dialog (2 steps in edit mode) — type picker → billing config → apply to products |
| `assets/vue-dashboard/src/components/BlueprintAnalyticsCard.vue` | Metric grid component fetched per-blueprint from `/blueprints/{id}/analytics` |
| `assets/vue-dashboard/src/components/BlueprintProductPicker.vue` | Linked-product list + remote-search add + detach; calls `/apply` and `/products` endpoints |

Router: `/blueprints` route in `assets/vue-dashboard/src/router/index.js`.
Admin menu: "Blueprints" submenu added in `includes/admin/class-dashboard.php` between Customers and Settings.

### Category / Tag Scope (push to entire taxonomy)

Blueprints can define a **scope** stored inside the `settings` JSON:

```json
{ "scope_category_ids": [12, 45], "scope_tag_ids": [7] }
```

**Priority hierarchy** (higher wins in conflicts — stored as `_recurio_blueprint_link_type`):

| Link type | Priority | How set |
|---|---|---|
| `direct` | 3 (highest) | Merchant manually applies via admin or REST |
| `tag` | 2 | `apply-scope` matched product's tag |
| `category` | 1 (lowest) | `apply-scope` matched product's category |

`can_stamp()` in `class-blueprints-api.php` guards every stamp: a lower-priority attempt never overwrites a higher-priority existing link.

**Auto-enroll new products:** `save_post_product` hook (`maybe_auto_stamp_product()`) fires on every product save. It checks the saved product's categories/tags against all active scoped blueprints and stamps the best match automatically — no merchant intervention needed after setting up scope once.

**REST additions:**
- `POST /blueprints/{id}/apply-scope` — applies to all products matching configured category/tag IDs; respects priority; auto-promotes blueprint to `active`.
- `GET /blueprints/{id}/scope-coverage` — dry-run count + category/tag labels (no stamping).
- `GET /blueprints/taxonomy-search?taxonomy=product_cat&search=...` — term search for the Vue scope picker.

**Vue wizard step 3** now has two tabs:
- "By Product" — `BlueprintProductPicker` (direct links)
- "By Category / Tag" — remote-search `el-select` for categories and tags, priority explainer, live coverage preview, "Apply scope now" button.

**`BlueprintProductPicker.vue`** shows link type badge (Direct / Tag / Category) on each row; only `direct` links have an active "Remove" button — scope-linked products show a tooltip explaining they must be removed via scope.

**Product edit page** (`class-woocommerce-product.php`) shows the link type label ("via Category", "via Tag", "Direct link") next to the blueprint name badge, with a contextual tooltip explaining how to change or override.

### What to keep in sync when extending

- Adding a new Blueprint settings field → update `sanitize_settings()` AND `stamp_settings_on_product()` meta map in `class-blueprints-api.php`, then mirror in `BlueprintWizard.vue` step 2 UI.
- Changing the type enum → update `$allowed_types` in `extract_blueprint_data()` AND the `types` array in `BlueprintWizard.vue`.
- Analytics fields → update both `get_analytics()` and `BlueprintAnalyticsCard.vue` metric tiles.
- Scope fields (`scope_category_ids`, `scope_tag_ids`) are sanitised inside the `scope arrays` block of `sanitize_settings()` — keep that block separate from scalar fields.
