<?php
/**
 * Author Topics Scheduler
 *
 * Handles scheduled generation of topics for authors.
 * Separate from post generation scheduling.
 *
 * @package AI_Post_Scheduler
 * @since 1.8.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Author_Topics_Scheduler
 *
 * Schedules and executes topic generation for authors.
 */
class AIPS_Author_Topics_Scheduler extends AIPS_Author_Slice_Scheduler_Base {

	/**
	 * WordPress cron hook name for per-author topic-generation slices.
	 *
	 * @var string
	 */
	const SLICE_HOOK = 'aips_process_author_topics_slice';

	/**
	 * Default minimum number of due authors that triggers per-author batching.
	 *
	 * When more than this many authors are due, individual single events are
	 * dispatched for each author rather than processing all of them inline.
	 * Override via the 'aips_author_topics_batch_threshold' filter.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_THRESHOLD = 3;

	/**
	 * @var self|null Singleton instance.
	 */
	private static $instance = null;

	/**
	 * Get the shared singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @var AIPS_Author_Topics_Generator Generator for topics
	 */
	private $topics_generator;

	/**
	 * @var AIPS_Interval_Calculator Calculator for scheduling intervals
	 */
	private $interval_calculator;

	/**
	 * @var AIPS_Notifications Notifications service
	 */
	private $notifications;

	/**
	 * @var AIPS_Batch_Queue_Service|null Lazy-loaded batch queue service.
	 */
	private $batch_queue_service;

	/**
	 * @var AIPS_Generation_Claims_Repository Atomic generation claims.
	 */
	private $claims_repository;

	/**
	 * @var AIPS_Generation_State_Repository Persisted attempt/retry state.
	 */
	private $state_repository;

	/**
	 * @var AIPS_Generation_Retry_Scheduler Outcome-driven scheduling/retry policy.
	 */
	private $retry_scheduler;

	/**
	 * Initialize the scheduler.
	 */
	public function __construct() {
		$this->authors_repository = new AIPS_Authors_Repository();
		$this->topics_generator = new AIPS_Author_Topics_Generator();
		$this->logger = new AIPS_Logger();
		$this->interval_calculator = new AIPS_Interval_Calculator();
		$this->history_service = new AIPS_History_Service();
		$this->notifications = new AIPS_Notifications();
		$this->job_scheduler = new AIPS_Job_Scheduler();
		$this->claims_repository = new AIPS_Generation_Claims_Repository();
		$this->state_repository = new AIPS_Generation_State_Repository();
		$this->retry_scheduler = new AIPS_Generation_Retry_Scheduler($this->state_repository, $this->job_scheduler, $this->logger, $this->notifications);
	}

	/**
	 * Get the cron hook name for this scheduler's slice processing.
	 *
	 * @return string The WordPress cron hook name.
	 */
	protected function get_slice_hook(): string {
		return self::SLICE_HOOK;
	}

	/**
	 * Get the filter name for stagger seconds configuration.
	 *
	 * @return string The WordPress filter name.
	 */
	protected function get_stagger_filter(): string {
		return 'aips_author_topics_slice_stagger_seconds';
	}

	/**
	 * Get the default stagger seconds value.
	 *
	 * @return int Default number of seconds between author slices.
	 */
	protected function get_default_stagger_seconds(): int {
		return 10;
	}

	/**
	 * Get the history service type for this scheduler.
	 *
	 * @return string Type string for history service.
	 */
	protected function get_history_type(): string {
		return 'author_topic_generation';
	}

	/**
	 * Get the human-readable log type for this scheduler.
	 *
	 * @return string Log type.
	 */
	protected function get_log_type(): string {
		return 'author-topics';
	}

	/**
	 * Get the retry cron hook name for this scheduler.
	 *
	 * @return string The WordPress cron hook name for retries.
	 */
	protected function get_retry_hook(): string {
		return 'aips_retry_failed_author_slices_topics';
	}

	/**
	 * Lazy-load the batch queue service.
	 *
	 * @return AIPS_Batch_Queue_Service
	 */
	private function get_batch_queue_service(): AIPS_Batch_Queue_Service {
		if ( $this->batch_queue_service === null ) {
			$this->batch_queue_service = new AIPS_Batch_Queue_Service();
		}
		return $this->batch_queue_service;
	}
	
	/**
	 * Process topic generation for all due authors.
	 *
	 * When the number of due authors meets or exceeds the configured threshold
	 * (aips_author_topics_batch_threshold), individual single cron events are
	 * dispatched for each author instead of processing all of them inline.
	 * This prevents PHP timeout issues when many authors are due simultaneously.
	 *
	 * This is called by WordPress cron on the scheduled interval.
	 */
	public function process_topic_generation() {
		$this->logger->log('Starting scheduled topic generation', 'info');
		
		// Get all authors due for topic generation
		$due_authors = $this->authors_repository->get_due_for_topic_generation();
		
		if (empty($due_authors)) {
			$this->logger->log('No authors due for topic generation', 'info');
			return;
		}

		$author_count = count($due_authors);
		$this->logger->log("Found {$author_count} authors due for topic generation", 'info');

		// Determine whether to dispatch per-author slices.
		$threshold = max(1, (int) apply_filters('aips_author_topics_batch_threshold', self::DEFAULT_BATCH_THRESHOLD));

		if ( $author_count >= $threshold ) {
			$this->dispatch_author_slices( $due_authors );
			return;
		}
		
		// Below threshold — process inline (original behaviour).
		foreach ($due_authors as $author) {
			AIPS_Correlation_ID::generate();
			try {
				$this->generate_topics_for_author($author);
			} finally {
				AIPS_Correlation_ID::reset();
			}
		}
		
		$this->logger->log('Completed scheduled topic generation', 'info');
	}

	/**
	 * Process topic generation for a single author slice.
	 *
	 * This is the callback for the `aips_process_author_topics_slice` cron hook.
	 * It loads the author by ID and calls generate_topics_for_author().
	 *
	 * @param int    $author_id      ID of the author to process.
	 * @param string $correlation_id Correlation ID for tracing.
	 */
	public function process_author_slice( int $author_id, string $correlation_id = '' ): void {
		if ( ! empty( $correlation_id ) ) {
			AIPS_Correlation_ID::set( $correlation_id );
		} else {
			AIPS_Correlation_ID::generate();
		}

		try {
			$author = $this->authors_repository->get_by_id( $author_id );
			if ( ! $author ) {
				$this->logger->log(
					"Author topics slice: author {$author_id} not found — skipping.",
					'warning'
				);
				return;
			}

			$this->generate_topics_for_author( $author );
		} finally {
			AIPS_Correlation_ID::reset();
		}
	}

	/**
	 * Retry failed author topic slices.
	 *
	 * This is the callback for the `aips_retry_failed_author_slices_topics` cron hook.
	 * It re-attempts to dispatch slice events for authors that failed to schedule earlier.
	 *
	 * @param string $author_ids_json JSON-encoded array of author IDs.
	 * @param string $correlation_id  Correlation ID for tracing.
	 */
	public function retry_failed_topic_slices( string $author_ids_json, string $correlation_id = '' ): void {
		$this->retry_failed_slices( $author_ids_json, $correlation_id );
	}

	/**
	 * Generate topics for a specific author.
	 *
	 * @param object $author Author object from database.
	 * @return bool True on success, false on failure.
	 */
	public function generate_topics_for_author($author) {
		$this->logger->log("Generating topics for author: {$author->name} (ID: {$author->id})", 'info');

		// Acquire an atomic, expiring claim so two workers cannot generate
		// topics for the same author concurrently. Released in finally below.
		$claim_token = $this->claims_repository->claim_author_topic_generation((int) $author->id);
		if (false === $claim_token) {
			$this->logger->log("Topic generation for author {$author->id} skipped — already running.", 'warning');
			$result = new AIPS_Author_Topic_Generation_Result(
				(int) $author->id,
				isset($author->topic_generation_quantity) ? (int) $author->topic_generation_quantity : 0,
				'',
				(string) AIPS_Correlation_ID::get()
			);
			$result->mark_already_running();
			$this->retry_scheduler->handle_outcome(
				AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC,
				$author,
				AIPS_Generation_Outcome::from_topic_result($result),
				(string) AIPS_Correlation_ID::get()
			);
			return false;
		}

		try {
			return $this->run_topic_generation($author);
		} finally {
			$this->claims_repository->release_claim(
				AIPS_Generation_Claims_Repository::TYPE_AUTHOR_TOPIC_GENERATION,
				(int) $author->id,
				$claim_token
			);
		}
	}

	/**
	 * Execute topic generation for an author (claim already held).
	 *
	 * @param object $author Author object from database.
	 * @return bool True on success, false on failure.
	 */
	private function run_topic_generation($author) {
		$correlation_id = (string) AIPS_Correlation_ID::get();
		$this->state_repository->record_attempt(
			AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC,
			(int) $author->id,
			$correlation_id
		);

		// Generate topics using the generator (rich result object).
		$result  = $this->topics_generator->generate_topics_with_result($author);
		$outcome = AIPS_Generation_Outcome::from_topic_result($result);

		// Apply the outcome-driven scheduling policy: records state and, for
		// transient failures, schedules a bounded retry with backoff.
		$decision = $this->retry_scheduler->handle_outcome(
			AIPS_Generation_State_Repository::FLOW_AUTHOR_TOPIC,
			$author,
			$outcome,
			$correlation_id
		);

		if (!$result->is_success()) {
			$error_message = $result->get_error() instanceof WP_Error
				? $result->get_error()->get_error_message()
				: __('No topics were generated', 'ai-post-scheduler');

			$this->logger->log("Failed to generate topics for author {$author->id}: {$error_message} (outcome: {$outcome->get_outcome()})", 'error');

			$fail_history = $this->history_service->create('author_topic_generation', array(
				'author_id' => $author->id,
			));
			$fail_history->record(
				'activity',
				sprintf(
					__('Failed to generate topics for author "%s": %s', 'ai-post-scheduler'),
					$author->name,
					$error_message
				),
				array(
					'event_type'   => 'author_topic_generation',
					'event_status' => 'failed',
				),
				null,
				array(
					'author_id'          => $author->id,
					'author_name'        => $author->name,
					'field_niche'        => $author->field_niche,
					'requested_quantity' => $author->topic_generation_quantity,
					'error'              => $error_message,
					'outcome'            => $outcome->get_outcome(),
					'retry_scheduled'    => $decision['retry_scheduled'],
				)
			);

			// Only advance the recurring schedule when the policy says so — a
			// transient failure leaves the schedule in place so the retry (not a
			// full interval) is what re-attempts the work.
			if ($decision['advance']) {
				$this->update_author_schedule($author);
			}
			return false;
		}

		if ($decision['advance']) {
			$this->update_author_schedule($author);
		}

		$topic_count = $result->get_persisted_count();
		$event_status = $result->is_partial() ? 'partial' : 'success';
		$success_history = $this->history_service->create('author_topic_generation', array(
			'author_id' => $author->id,
		));
		$success_history->record(
			'activity',
			sprintf(
				$result->is_partial()
					? __('Generated %1$d of %2$d requested topics for author "%3$s"', 'ai-post-scheduler')
					: __('Generated %1$d topics for author "%3$s"', 'ai-post-scheduler'),
				$topic_count,
				(int) $author->topic_generation_quantity,
				$author->name
			),
			array(
				'event_type'   => 'author_topic_generation',
				'event_status' => $event_status,
			),
			null,
			array(
				'author_id'          => $author->id,
				'author_name'        => $author->name,
				'field_niche'        => $author->field_niche,
				'topics_generated'   => $topic_count,
				'requested_quantity' => $author->topic_generation_quantity,
				'outcome'            => $outcome->get_outcome(),
			)
		);

		$this->logger->log("Successfully generated topics for author {$author->id} (outcome: {$outcome->get_outcome()})", 'info');

		// Create admin bar notification
		$this->notifications->author_topics_generated($author->name, $topic_count, $author->id);

		return true;
	}

	/**
	 * Retry cron callback: re-run scheduled topic generation for one author.
	 *
	 * Fired by the aips_retry_author_topic_generation hook. Re-enters the same
	 * scheduled path (claim + outcome policy), which will schedule the next
	 * bounded retry if this attempt also fails.
	 *
	 * @param int    $author_id      Author ID.
	 * @param string $correlation_id Correlation ID for tracing.
	 * @param int    $attempt        Retry attempt number (for logging).
	 * @return void
	 */
	public function retry_topic_generation(int $author_id, string $correlation_id = '', int $attempt = 0): void {
		if ('' !== $correlation_id) {
			AIPS_Correlation_ID::set($correlation_id);
		}

		try {
			$author = $this->authors_repository->get_by_id($author_id);
			if (!$author) {
				$this->logger->log("Topic generation retry: author {$author_id} not found — skipping.", 'warning');
				return;
			}

			$this->logger->log("Retrying topic generation for author {$author_id} (attempt {$attempt}).", 'info');
			$this->generate_topics_for_author($author);
		} finally {
			if ('' !== $correlation_id) {
				AIPS_Correlation_ID::reset();
			}
		}
	}
	
	/**
	 * Update the author's topic generation schedule.
	 *
	 * @param object $author Author object from database.
	 */
	private function update_author_schedule($author) {
		$ran_at = AIPS_DateTime::now()->timestamp();

		// Advance from the actual execution time so the next run reflects when
		// work really happened instead of preserving a stale missed-run phase.
		$next_run = $this->interval_calculator->calculate_next_run($author->topic_generation_frequency, $ran_at);
		
		$this->authors_repository->update_topic_generation_schedule($author->id, $next_run);
		
		$this->logger->log("Updated topic generation schedule for author {$author->id}. Next run: {$next_run}", 'info');
	}
	
	/**
	 * Manually trigger topic generation for an author (e.g., from admin UI).
	 *
	 * Manual runs do NOT advance the recurring schedule by default (finding 4):
	 * a manual "Run Now" is a one-off and should not shift the next scheduled
	 * occurrence unless the administrator explicitly asks to reset it.
	 *
	 * @param int  $author_id        Author ID.
	 * @param bool $advance_schedule Whether to reset the author's next run from now.
	 * @return AIPS_Author_Topic_Generation_Result|WP_Error Structured result or validation/claim error.
	 */
	public function generate_now($author_id, $advance_schedule = false) {
		$author = $this->authors_repository->get_by_id($author_id);

		if (!$author) {
			return new WP_Error('invalid_author', 'Author not found');
		}

		// Acquire an atomic, expiring claim so a manual run cannot overlap with a
		// scheduled/cron run or another manual run for the same author.
		$claim_token = $this->claims_repository->claim_author_topic_generation((int) $author->id);
		if (false === $claim_token) {
			return new WP_Error('already_running', __('A topic generation run for this author is already in progress.', 'ai-post-scheduler'));
		}

		try {
			$result = $this->topics_generator->generate_topics_with_result($author);

			// Keep manual "Run Now" behavior aligned with cron runs by advancing
			// schedule timestamps regardless of success/failure to avoid re-running
			// immediately on the next cron tick.
			if ($advance_schedule) {
				$this->update_author_schedule($author);
			}

			return $result;
		} finally {
			$this->claims_repository->release_claim(
				AIPS_Generation_Claims_Repository::TYPE_AUTHOR_TOPIC_GENERATION,
				(int) $author->id,
				$claim_token
			);
		}
	}
}
