# Authors Review Phase 2–3 Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every remaining Phase 2 and Phase 3 acceptance gap on `qa/authors-review`.

**Architecture:** Extend the existing batch-item repository with lease recovery, keep manual-result shaping in a focused presenter, and enrich the existing aggregate generation status rather than adding per-author queries. Reuse structured generation results and existing AJAX/notification boundaries.

**Tech Stack:** PHP 8.2+, WordPress 5.8+, PHPUnit 9.6, jQuery admin UI, WP-Cron, existing AIPS repositories and services.

## Global Constraints

- Preserve Phase 1/2 branches and PRs; modify only `qa/authors-review`.
- Use repository-owned SQL, `AIPS_DateTime`, `AIPS_Ajax_Response`, nonce and `manage_options` checks.
- Add every behavior through red-green-refactor and run focused tests from `ai-post-scheduler/`.
- Keep manual actions available when scheduled flows are paused.

---

### Task 1: Recover abandoned author-topic batch items

**Files:**
- Modify: `ai-post-scheduler/includes/class-aips-author-topic-batch-items-repository.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topic-batch-service.php`
- Modify: `ai-post-scheduler/ai-post-scheduler.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Batch_Recovery_Unit.php`

**Interfaces:**
- Produces: `recover_stale_running(string $batch_id, int $lease_seconds): int` and recovery-before-status/processing behavior.

- [ ] Write a failing repository/service test proving stale `running` items return to `queued`, fresh leases remain running, and terminal rows never reset.
- [ ] Run the test and confirm the recovery contract is absent.
- [ ] Implement a filterable lease (`aips_author_topic_batch_item_lease`) and atomic recovery query.
- [ ] Redispatch recovered work without incrementing processed counts twice.
- [ ] Run recovery and batch regression tests.

### Task 2: Make manual generation results fully actionable

**Files:**
- Create: `ai-post-scheduler/includes/class-aips-author-generation-result-presenter.php`
- Modify: `ai-post-scheduler/includes/class-aips-schedule-controller.php`
- Modify: `ai-post-scheduler/includes/class-aips-authors-controller.php`
- Modify: `ai-post-scheduler/assets/js/authors.js`
- Modify: `ai-post-scheduler/includes/class-aips-admin-assets.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Generation_Result_Presenter_Unit.php`

**Interfaces:**
- Produces: normalized post/topic payloads with post links, failure topic titles/reasons, retryable topic IDs, review URL, refill counts, schedule state, and updated counters.

- [ ] Write failing presenter tests for full, partial, failed, no-work, and already-running outcomes.
- [ ] Verify the tests fail because retry links/details/counters are absent.
- [ ] Implement normalized payloads and a retry-failed-topics AJAX path using the existing unified generation endpoint per requested topic.
- [ ] Render all failures and generated links, show refill attempts and pending-review link, and refresh counters/cards without reloading.
- [ ] Run presenter/controller tests and JavaScript syntax checks.

### Task 3: Complete status aggregates and notification semantics

**Files:**
- Modify: `ai-post-scheduler/includes/class-aips-generation-state-repository.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-generation-status-repository.php`
- Modify: `ai-post-scheduler/templates/admin/authors.php`
- Modify: `ai-post-scheduler/includes/class-aips-notifications.php`
- Modify: `ai-post-scheduler/includes/class-aips-author-topics-scheduler.php`
- Modify: `ai-post-scheduler/includes/class-aips-authors-controller.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Generation_Status_Unit.php`
- Test: `ai-post-scheduler/tests/Test_AIPS_Author_Topic_Notification_Unit.php`

**Interfaces:**
- Produces: last requested/generated counts and escaped error details in aggregate status; distinct partial-topic and already-running notifications with dedupe keys.

- [ ] Write failing tests for requested/generated/error status fields and notification routing.
- [ ] Run tests and confirm current generic notification behavior fails them.
- [ ] Persist/read last-result counts without adding N+1 queries.
- [ ] Render last-run counts and recent errors; dispatch partial and claim-contention notifications with actionable URLs.
- [ ] Run status/notification regressions.

### Task 4: Complete integration and UI verification

**Files:**
- Modify/add focused tests under `ai-post-scheduler/tests/` only as required by uncovered contracts.
- Update: `CHANGELOG.md`, `docs/FEATURE_LIST.md`, `docs/HOOKS.md`, `docs/AI_AGENT_REFERENCE.md`.

**Interfaces:**
- Produces: evidence for activation combinations, due queries, aggregate query bounds, manual serializers, localization/escaping, batch recovery, and browser-visible states.

- [ ] Run all focused database-free tests and every existing author-generation test supported by the environment.
- [ ] Run PHP syntax, JavaScript syntax, diff checks, and repository-boundary checks.
- [ ] Start the local WordPress stack if available and smoke-test author edit controls, status cards, bulk progress/recovery, partial results, paused, and retrying states; capture screenshots.
- [ ] If infrastructure is unavailable, record the exact blocker and do not claim browser/full-integration completion.
- [ ] Request AJAX, DB, generation-pipeline, and final QA reviews; resolve all critical/important findings.
- [ ] Commit the verified completion on `qa/authors-review`.
