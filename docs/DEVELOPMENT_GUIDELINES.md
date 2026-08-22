# Development Guidelines

Project-specific rules for the AI Post Scheduler plugin. Follow these in addition to the canonical guidance in [AGENTS.md](../AGENTS.md).

---

## JS: Use AIPS.Templates for HTML

Never build HTML via string concatenation in JS. Always use:
- `AIPS.Templates.render(id, data)` — auto-escapes all `{{token}}` values (safe for text and attributes).
- `AIPS.Templates.renderRaw(id, data)` — no escaping; only for already-trusted HTML.

Define markup in `<script type="text/html" id="tmpl-...">` blocks in the relevant admin template.

---

## DB Schema Changes: AIPS_DB_Manager + Version Bump

For any schema change (new table, column, or index):
1. Update `AIPS_DB_Manager::get_schema()`.
2. `install_tables()` + `dbDelta` handle the rest — no extra wiring needed.
3. Bump **both** the `Version:` plugin header and `AIPS_VERSION` constant in `ai-post-scheduler.php`.
4. Create a repository class in `includes/` for any new table.

---

## Plugin Settings: Three-Step Pattern

1. **Default** — add `'key' => default_value` to `AIPS_Config::get_default_options()`.
2. **Register** — call `register_setting()` in `AIPS_Settings::register_settings()`, reading the default from `AIPS_Config::get_default_options()`.
3. **Read** — always use `AIPS_Config::get_instance()->get_option('key')` so the declared default is the fallback.

---

## SQL: Repository Classes Only

All `$wpdb` queries belong in `class-aips-*-repository.php` files. Never write inline SQL in Controllers, Services, Schedulers, or templates. If a needed query method doesn't exist, add it to the appropriate repository.

---

## JS Feedback: Use AIPS.Utilities.showToast, Never alert()

Never call the native `alert()` function. Always use:
- `AIPS.Utilities.showToast(message, type, opts)` — `type` is `'success'`, `'error'`, `'warning'`, or `'info'`.
- Plain-text messages are auto-escaped. Pass `opts.isHtml = true` only for pre-trusted HTML.
- Set `opts.duration = 0` to suppress auto-dismiss.

The shorthand `AIPS.showToast(message, type, opts)` delegates to the same method.

---

## JS Confirmation: Use AIPS.Utilities.confirm, Never confirm()

Never call the native `confirm()` function. Always use:
- `AIPS.Utilities.confirm(message, heading, buttons)` — renders a styled, accessible modal dialog.
- `buttons` is an array of `{ label, className, action }` objects. Omit `action` for a close-only button.
- The modal closes on the action callback, Escape key, or backdrop click.

---

## JS DOM Refresh: Never Use location.reload()

Never call `location.reload()` after an AJAX action. Instead:
- Re-fetch the updated data via a follow-up AJAX call and re-render the affected UI region using `AIPS.Templates.render()` / `renderRaw()`.
- Only replace or patch the specific DOM nodes that changed; leave the rest of the page untouched.

---

## Date and Time Handling: AIPS_DateTime, Never Native PHP Date Functions

All datetime columns in the database are stored as `BIGINT UNSIGNED` Unix timestamps (not MySQL DATETIME strings). Use the `AIPS_DateTime` class for all date/time operations:

- **DB writes:** store the raw int — `AIPS_DateTime::now()->timestamp()`.
- **Display:** `AIPS_DateTime::fromTimestamp($ts)->toDisplay($format)` or `->toHumanDiff()`.
- **Parsing stored strings (legacy data only):** `AIPS_DateTime::fromMysql($str)->timestamp()`.
- **ISO 8601:** `AIPS_DateTime::now()->toIso8601()`.

Never use `current_time()`, `strtotime()`, `date()`, `date_i18n()`, or `gmdate()` in new code. For `updated_at` columns, repository UPDATE methods must set the value explicitly — `ON UPDATE CURRENT_TIMESTAMP` does not fire on BIGINT columns.

---

## Table Gateway & CRUD Persistence Abstraction (Optional)

The `AIPS_Table_Gateway` class provides safe, prepared CRUD operations (`find_by_id`, `find_all`, `insert`, `update_by_id`, `delete_by_id`) for simple database tables.

- **Approved Use Cases:** Simple repositories with basic single-table persistence operations (e.g. `AIPS_Voices_Repository` and `AIPS_Article_Structure_Repository`).
- **Rejected Use Cases:** Complex repositories with multi-table joins, custom query logic, or where the persistence is tightly coupled with domain/orchestration rules (e.g. `AIPS_History_Repository`, `AIPS_Campaigns_Repository`, or `AIPS_Schedule_Repository`).
- **Core Rule:** Concrete repositories must retain validation, hooks, cache invalidation, and domain-specific operations. The gateway only manages raw database interaction and error capture.

---

## Admin UI Design System

For all admin interface work, use `ai-post-scheduler/docs/Design_Guidelines.md` as the single source of truth for tokens, shared component classes, approved usage, and migration patterns.
