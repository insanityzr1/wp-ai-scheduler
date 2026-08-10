# Authors Review Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the remaining work in `Authors Review Plan.md` on one unified `qa/authors-review` branch without modifying the existing phase branches or pull requests.

**Architecture:** Keep generation concurrency, state, and outcome policy in the Phase 1/2 repositories and services already present. Extend those contracts through AJAX and UI instead of converting them back to legacy arrays, introduce one focused author-topic batch service/controller over the existing bulk batch job store, and isolate topic candidate validation/refill from persistence so it can be tested deterministically.

**Tech Stack:** PHP 8.2+, WordPress 5.8+, PHPUnit, jQuery admin UI, WordPress AJAX/WP-Cron, existing AIPS repositories/services.

## Global Constraints

- Use `AIPS_` class names, matching `class-aips-*.php` filenames, tabs, and `array()` syntax.
- Use `AIPS_Container` for registered singletons and register every new AJAX action in `AIPS_Ajax_Registry::$map`.
- Controllers own nonce/capability/sanitization/JSON response logic; repositories own SQL.
- Use `AIPS_DateTime` for timestamps and existing history, notification, logger, and correlation-ID services.
- Preserve current phase branches and PRs; all changes land only on `qa/authors-review`.
- Follow red-green-refactor for behavior changes and run focused tests from `ai-post-scheduler/`.

---

### Task 1: Structured result propagation and topic-limit consistency

**Files:**
- Modify: `ai-post-scheduler/includes/class-aips-author-post-generator.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topics-scheduler.php`
- Modify: `ai-post-scheduler/includes/class-aips-unified-schedule-service.php`
- Modify: `ai-post-scheduler/includes/class-aips-authors-controller.php`
- Modify: `ai-post-scheduler/includes/class-aips-schedule-controller.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Post_Generator_Claims.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Generation_Result_Callers.php`

**Interfaces:**
- Consumes: `AIPS_Author_Post_Generation_Result`, `AIPS_Author_Topic_Generation_Result`, generation claims, author `max_posts_per_topic`.
- Produces: structured results from manual/unified author flows; a repository-backed eligibility check for direct generation; legacy conversion only at explicitly single-post boundaries.

- [ ] Add failing tests proving direct generation refuses a topic at its post limit, regeneration has explicit replacement semantics, topic claims release on errors/exceptions, and unified/manual callers preserve partial failures.
- [ ] Run the focused tests and confirm failures are caused by legacy conversion or missing eligibility checks.
- [ ] Add the smallest repository/generator changes needed to enforce the limit and return result objects through scheduler/service boundaries.
- [ ] Update AJAX serializers to return `result`, generated IDs/links, failures/skips, and schedule state while preserving compatibility fields.
- [ ] Run the focused tests and PHP syntax checks for touched files.

### Task 2: Manual scheduling UI and already-running recheck

**Files:**
- Modify: `ai-post-scheduler/includes/class-aips-generation-outcome.php`
- Modify: `ai-post-scheduler/includes/class-aips-generation-retry-scheduler.php`
- Modify: `ai-post-scheduler/includes/class-aips-notifications.php`
- Modify: `ai-post-scheduler/assets/js/authors.js`
- Modify: `ai-post-scheduler/assets/js/schedule.js` or the existing unified-schedule script discovered during implementation
- Modify: `ai-post-scheduler/templates/admin/authors.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Generation_Outcome.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Generation_Retry_Scheduler.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Manual_Schedule_Semantics.php`

**Interfaces:**
- Produces: short bounded recheck for `already_running`; `reset_schedule` opt-in from both manual topic/post UIs; visible preserved/reset state.

- [ ] Add failing outcome/retry tests for a short already-running recheck that does not advance the recurring schedule.
- [ ] Add failing controller tests proving manual requests preserve schedule by default and reset only when requested.
- [ ] Implement the recheck policy with a dedicated filterable delay and deduplicated notification.
- [ ] Add reset controls and send/display `reset_schedule`, `previous_next_run`, `current_next_run`, and `schedule_changed`.
- [ ] Run focused PHP tests, PHP syntax checks, and available JavaScript static checks.

### Task 3: Queued bulk author-topic generation

**Files:**
- Create: `ai-post-scheduler/includes/class-aips-author-topic-batch-service.php`
- Create: `ai-post-scheduler/includes/class-aips-author-topic-batch-controller.php`
- Modify: `ai-post-scheduler/includes/class-aips-ajax-registry.php`
- Modify: `ai-post-scheduler/ai-post-scheduler.php`
- Modify: `ai-post-scheduler/assets/js/authors.js`
- Modify: `ai-post-scheduler/includes/class-aips-admin-assets.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Batch_Service.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Batch_Controller.php`

**Interfaces:**
- Produces: `enqueue(array $author_ids, string $request_key): array|WP_Error`, `get_status(string $batch_id): array|WP_Error`, and `cancel(string $batch_id): bool|WP_Error`; AJAX actions for enqueue/status/cancel.
- Uses: existing bulk batch job store/queue service and per-author topic scheduler so claims/outcomes remain authoritative.

- [ ] Add failing service tests for validation, deduplication, missing authors, idempotent active submissions, partial enqueue, status aggregation, and cancellation.
- [ ] Add failing controller tests for nonce, `manage_options`, sanitization, and registry mapping.
- [ ] Implement the focused service/controller and child job callback without SQL in the controller.
- [ ] Replace `Promise.allSettled()` with one enqueue call, status polling, progress states, per-author errors, completion refresh, and cancellation.
- [ ] Add retry/batch-completion notifications with author/history/batch links.
- [ ] Run focused service/controller tests and static checks.

### Task 4: Topic validation, duplicate accounting, and bounded refill

**Files:**
- Create: `ai-post-scheduler/includes/class-aips-author-topic-candidate-validator.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topics-generator.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topic-generation-result.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topics-prompt-builder.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Candidate_Validator.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Refill.php`

**Interfaces:**
- Produces: validator output with accepted candidates plus rejection records categorized as invalid, exact duplicate, or fuzzy duplicate; result fields for returned, accepted, invalid, exact/fuzzy duplicate, persisted, missing, and refill attempts.
- Refill limit: default two attempts through `aips_author_topic_refill_max_attempts`.

- [ ] Add failing tests for schema bounds, multibyte titles, whitespace/punctuation, score handling, keyword normalization, same-response duplicates, stored-topic duplicates across every status, and rejection reasons.
- [ ] Add failing tests for refill success, exhaustion, partial status, and shortfall prompts containing accepted/rejected titles.
- [ ] Implement the validator and strengthen the structured JSON schema.
- [ ] Implement refill as a bounded loop that requests only the remaining count and persists once using the existing run ID.
- [ ] Serialize complete quality/refill metrics and ensure partial notifications are not reported as full success.
- [ ] Run focused generator/validator tests and syntax checks.

### Task 5: Independent flow controls and generation status aggregates

**Files:**
- Modify: `ai-post-scheduler/includes/class-aips-authors-controller.php`
- Modify: `ai-post-scheduler/includes/class-aips-authors-repository.php`
- Create: `ai-post-scheduler/includes/class-aips-author-generation-status-repository.php`
- Modify: `ai-post-scheduler/templates/admin/authors.php`
- Modify: `ai-post-scheduler/assets/js/authors.js`
- Modify author suggestion/import/export code discovered by `rg` during implementation
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Flow_Controls.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Generation_Status_Repository.php`

**Interfaces:**
- Produces: master/topic/post activation persistence and one aggregate status map keyed by author ID containing attempts, successes, outcomes, retries, claims, counts, and next runs.

- [ ] Add failing tests for all activation combinations and due-author query behavior.
- [ ] Add failing aggregate tests that assert bounded query count and correct topic/post flow data.
- [ ] Persist/edit/suggest/import/export all three flags while keeping manual actions available.
- [ ] Build separate topic and post status cards from aggregate data without per-author queries.
- [ ] Add paused/running/retrying/failed localized states with escaped titles, errors, and URLs.
- [ ] Run focused repository/controller/rendering tests and syntax checks.

### Task 6: Rich manual feedback, documentation, and release verification

**Files:**
- Modify: `ai-post-scheduler/assets/js/authors.js`
- Modify: `ai-post-scheduler/includes/class-aips-admin-assets.php`
- Modify: `CHANGELOG.md`
- Modify: `docs/FEATURE_LIST.md`
- Modify: `docs/HOOKS.md`
- Modify: `docs/MIGRATIONS.md`
- Modify: `docs/AI_AGENT_REFERENCE.md`
- Test: manual result/controller/rendering tests from Tasks 1-5

**Interfaces:**
- Produces: full/partial/failed/already-running topic and post messages with links, retry-failed action, counts, refill state, and schedule state.

- [ ] Add failing UI/controller serialization tests for full, partial, failed, and already-running outcomes.
- [ ] Render generated-post links, topic failure details, retry-failed action, topic quality counts, refill state, and schedule state without full-page reloads.
- [ ] Document claim/retry/refill filters, result contracts, AJAX actions, batch job type, activation semantics, schedule-reset policy, and `max_posts_per_topic` behavior.
- [ ] Run PHP syntax checks for every changed PHP file and JavaScript checks configured by the project.
- [ ] Run the targeted author-generation PHPUnit suite, then the full PHPUnit suite if the environment supports it.
- [ ] Perform browser smoke checks for the author edit form, status cards, batch progress, partial result, paused state, and retrying state; capture screenshots.
- [ ] Review the complete diff against every Phase 1-3 exit criterion and resolve all critical/important review findings.
