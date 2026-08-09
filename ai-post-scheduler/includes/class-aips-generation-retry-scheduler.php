<?php
/**
 * Generation Retry Scheduler
 *
 * Applies the outcome-driven scheduling policy to a generation run: records
 * attempt/retry state, decides whether the recurring schedule advances, and
 * schedules bounded retries with exponential-ish backoff + jitter for transient
 * failures (Phase 2, finding 3). Emits actionable notifications for retry
 * scheduled / retry budget exhausted / permanent error / no approved topics.
 *
 * @package AI_Post_Scheduler
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Generation_Retry_Scheduler
 */
class AIPS_Generation_Retry_Scheduler {

	/**
	 * Default retry budget (number of retry attempts before giving up).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ATTEMPTS = 3;

	/**
	 * Default backoff delays in seconds: ~5 min, ~15 min, ~1 hour.
	 *
	 * @var int[]
	 */
	const DEFAULT_DELAYS = array(300, 900, 3600);

	/**
	 * Retry cron hook for author topic generation.
	 */
	const HOOK_TOPIC = 'aips_retry_author_topic_generation';

	/**
	 * Retry cron hook for author post generation.
	 */
	const HOOK_POST = 'aips_retry_author_post_generation';

	/**
	 * @var AIPS_Generation_State_Repository
	 */
	private $state_repository;

	/**
	 * @var AIPS_Job_Scheduler
	 */
	private $job_scheduler;

	/**
	 * @var AIPS_Logger
	 */
	private $logger;

	/**
	 * @var AIPS_Notifications
	 */
	private $notifications;

	/**
	 * Constructor.
	 *
	 * @param AIPS_Generation_State_Repository|null $state_repository State repo.
	 * @param AIPS_Job_Scheduler|null               $job_scheduler    Job scheduler.
	 * @param AIPS_Logger|null                      $logger           Logger.
	 * @param AIPS_Notifications|null               $notifications    Notifications.
	 */
	public function __construct($state_repository = null, $job_scheduler = null, $logger = null, $notifications = null) {
		$this->state_repository = $state_repository ?: new AIPS_Generation_State_Repository();
		$this->job_scheduler    = $job_scheduler ?: new AIPS_Job_Scheduler();
		$this->logger           = $logger ?: new AIPS_Logger();
		$this->notifications    = $notifications ?: new AIPS_Notifications();
	}

	/**
	 * Resolve the retry cron hook for a flow.
	 *
	 * @param string $flow Flow type.
	 * @return string
	 */
	public static function hook_for_flow(string $flow): string {
		return AIPS_Generation_State_Repository::FLOW_AUTHOR_POST === $flow ? self::HOOK_POST : self::HOOK_TOPIC;
	}

	/**
	 * Resolve the retry budget (max attempts), filterable.
	 *
	 * @param string $flow Flow type.
	 * @return int
	 */
	private function get_max_attempts(string $flow): int {
		/**
		 * Filters the number of bounded retry attempts for a generation flow.
		 *
		 * @since 3.3.0
		 *
		 * @param int    $max  Maximum retry attempts.
		 * @param string $flow Flow type.
		 */
		$max = (int) apply_filters('aips_generation_retry_max_attempts', self::DEFAULT_MAX_ATTEMPTS, $flow);
		return max(0, $max);
	}

	/**
	 * Resolve the backoff delay schedule, filterable.
	 *
	 * @param string $flow Flow type.
	 * @return int[]
	 */
	private function get_delays(string $flow): array {
		/**
		 * Filters the backoff delay schedule (seconds) for generation retries.
		 *
		 * @since 3.3.0
		 *
		 * @param int[]  $delays Delay schedule per attempt.
		 * @param string $flow   Flow type.
		 */
		$delays = apply_filters('aips_generation_retry_delays', self::DEFAULT_DELAYS, $flow);
		if (!is_array($delays) || empty($delays)) {
			$delays = self::DEFAULT_DELAYS;
		}
		return array_values(array_map('intval', $delays));
	}

	/**
	 * Compute the delay for a given attempt, applying jitter and honouring a
	 * provider-supplied retry delay when it is larger.
	 *
	 * @param string   $flow             Flow type.
	 * @param int      $attempt          1-based attempt number.
	 * @param int|null $provider_retry_after Provider retry delay (seconds).
	 * @return int Delay in seconds.
	 */
	public function compute_delay(string $flow, int $attempt, $provider_retry_after = null): int {
		$delays = $this->get_delays($flow);
		$index  = max(0, min($attempt - 1, count($delays) - 1));
		$base   = max(1, (int) $delays[$index]);

		// Jitter: up to 20% of the base delay to avoid thundering herds.
		$jitter_pct = (float) apply_filters('aips_generation_retry_jitter', 0.2, $flow);
		$jitter_pct = max(0, min(1, $jitter_pct));
		$jitter     = $jitter_pct > 0 ? wp_rand(0, (int) round($base * $jitter_pct)) : 0;

		$delay = $base + $jitter;

		if (null !== $provider_retry_after && (int) $provider_retry_after > $delay) {
			$delay = (int) $provider_retry_after;
		}

		return (int) $delay;
	}

	/**
	 * Apply the scheduling policy for an outcome and (when warranted) schedule a
	 * bounded retry.
	 *
	 * @param string                 $flow      Flow type.
	 * @param object                 $author    Author object (needs ->id, ->name).
	 * @param AIPS_Generation_Outcome $outcome   Classified outcome.
	 * @param string                 $correlation_id Correlation ID for tracing.
	 * @return array{advance:bool, retry_scheduled:bool, next_retry_at:int, exhausted:bool}
	 */
	public function handle_outcome(string $flow, $author, AIPS_Generation_Outcome $outcome, string $correlation_id = ''): array {
		$author_id = (int) $author->id;
		$decision  = array(
			'advance'         => $outcome->advances_schedule(),
			'retry_scheduled' => false,
			'next_retry_at'   => 0,
			'exhausted'       => false,
		);

		if ($outcome->is_success()) {
			$this->state_repository->record_success($flow, $author_id, $outcome->get_outcome());
			return $decision;
		}

		// Record the failure and its classification.
		$this->state_repository->record_failure(
			$flow,
			$author_id,
			$outcome->get_outcome(),
			$outcome->get_error_code(),
			$outcome->get_error_message()
		);

		// No-work / already-running: no retry; advance per policy.
		if (AIPS_Generation_Outcome::NO_APPROVED_TOPICS === $outcome->get_outcome()) {
			$this->notify_no_approved_topics($flow, $author);
			return $decision;
		}

		if (AIPS_Generation_Outcome::ALREADY_RUNNING === $outcome->get_outcome()) {
			return $decision;
		}

		// Permanent error: notify the admin, never retry, never advance.
		if ($outcome->is_permanent()) {
			$this->notify_permanent_error($flow, $author, $outcome);
			return $decision;
		}

		if (!$outcome->should_retry()) {
			return $decision;
		}

		// Bounded retry with backoff.
		$existing     = $this->state_repository->get($flow, $author_id);
		$prev_attempt = $existing ? (int) $existing->retry_attempts : 0;
		$next_attempt = $prev_attempt + 1;
		$max_attempts = $this->get_max_attempts($flow);

		if ($next_attempt > $max_attempts) {
			// Budget exhausted — stop retrying and alert the admin.
			$this->state_repository->clear_retry($flow, $author_id);
			$decision['exhausted'] = true;
			$this->notify_retry_exhausted($flow, $author, $outcome, $prev_attempt);
			return $decision;
		}

		$delay        = $this->compute_delay($flow, $next_attempt, $outcome->get_retry_after());
		$retry_at     = AIPS_DateTime::now()->timestamp() + $delay;
		$hook         = self::hook_for_flow($flow);
		$correlation  = '' !== $correlation_id ? $correlation_id : (string) AIPS_Correlation_ID::get();

		$scheduled = $this->job_scheduler->schedule_simple(
			$hook,
			$retry_at,
			array($author_id, $correlation, $next_attempt),
			array(
				'job_type'       => $flow . '_generation_retry',
				'correlation_id' => $correlation,
				'retry_options'  => array('max_attempts' => 3),
				'metadata'       => array(
					'author_id'     => $author_id,
					'retry_attempt' => $next_attempt,
					'outcome'       => $outcome->get_outcome(),
				),
			)
		);

		if (!$scheduled) {
			$this->logger->log(
				sprintf('Failed to schedule %s generation retry for author %d', $flow, $author_id),
				'error'
			);
			return $decision;
		}

		$this->state_repository->set_next_retry($flow, $author_id, $retry_at, $next_attempt);
		$decision['retry_scheduled'] = true;
		$decision['next_retry_at']   = $retry_at;

		$this->logger->log(
			sprintf(
				'Scheduled %s generation retry %d/%d for author %d in %ds (outcome: %s)',
				$flow,
				$next_attempt,
				$max_attempts,
				$author_id,
				$delay,
				$outcome->get_outcome()
			),
			'info'
		);

		$this->notify_retry_scheduled($flow, $author, $outcome, $next_attempt, $max_attempts, $retry_at);

		return $decision;
	}

	// ---------------------------------------------------------------------
	// Notifications (guarded — the notifications service is optional in tests)
	// ---------------------------------------------------------------------

	private function notify_retry_scheduled($flow, $author, AIPS_Generation_Outcome $outcome, int $attempt, int $max, int $retry_at): void {
		if (!method_exists($this->notifications, 'generation_retry_scheduled')) {
			return;
		}
		$this->notifications->generation_retry_scheduled($flow, $author, $outcome->get_outcome(), $attempt, $max, $retry_at);
	}

	private function notify_retry_exhausted($flow, $author, AIPS_Generation_Outcome $outcome, int $attempts): void {
		if (!method_exists($this->notifications, 'generation_retry_exhausted')) {
			return;
		}
		$this->notifications->generation_retry_exhausted($flow, $author, $outcome->get_error_message(), $attempts);
	}

	private function notify_permanent_error($flow, $author, AIPS_Generation_Outcome $outcome): void {
		if (!method_exists($this->notifications, 'generation_permanent_error')) {
			return;
		}
		$this->notifications->generation_permanent_error($flow, $author, $outcome->get_error_code(), $outcome->get_error_message());
	}

	private function notify_no_approved_topics($flow, $author): void {
		if (AIPS_Generation_State_Repository::FLOW_AUTHOR_POST !== $flow) {
			return;
		}
		if (!method_exists($this->notifications, 'no_approved_topics')) {
			return;
		}
		$this->notifications->no_approved_topics($author);
	}
}
