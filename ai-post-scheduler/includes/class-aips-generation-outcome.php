<?php
/**
 * Generation Outcome
 *
 * Classifies the result of an author topic/post generation run into a single
 * canonical outcome, and encodes the scheduling policy for that outcome
 * (whether the recurring schedule advances, and whether a retry is warranted).
 *
 * This replaces ad-hoc branching on error strings inside controllers/schedulers
 * with one authoritative classifier (Phase 2, finding 3 & 4).
 *
 * @package AI_Post_Scheduler
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Generation_Outcome
 */
class AIPS_Generation_Outcome {

	const FULL_SUCCESS       = 'full_success';
	const PARTIAL_SUCCESS    = 'partial_success';
	const NO_APPROVED_TOPICS = 'no_approved_topics';
	const ALREADY_RUNNING    = 'already_running';
	const TRANSIENT_FAILURE  = 'transient_failure';
	const RATE_LIMIT         = 'rate_limit';
	const PARSING_SHORTFALL  = 'parsing_shortfall';
	const PERMANENT_ERROR    = 'permanent_error';
	const DB_FAILURE         = 'db_failure';

	/**
	 * Error codes that are permanent configuration/user errors — never retry.
	 *
	 * @var string[]
	 */
	const PERMANENT_CODES = array(
		'invalid_author',
		'invalid_config',
		'invalid_configuration',
		'missing_ai_engine',
		'no_connector',
		'invalid_post_author',
		'invalid_category',
		'inactive_flow',
		'insufficient_quota',
	);

	/**
	 * @var string One of the outcome constants.
	 */
	private $outcome;

	/**
	 * @var string Machine error code, when applicable.
	 */
	private $error_code;

	/**
	 * @var string Human-readable message, when applicable.
	 */
	private $error_message;

	/**
	 * @var int|null Provider-supplied retry delay in seconds, when available.
	 */
	private $retry_after;

	/**
	 * Constructor.
	 *
	 * @param string   $outcome       Outcome constant.
	 * @param string   $error_code    Machine error code.
	 * @param string   $error_message Human-readable message.
	 * @param int|null $retry_after   Provider-supplied retry delay in seconds.
	 */
	public function __construct(string $outcome, string $error_code = '', string $error_message = '', $retry_after = null) {
		$this->outcome       = $outcome;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
		$this->retry_after   = (null !== $retry_after) ? max(0, (int) $retry_after) : null;
	}

	/**
	 * Classify an author post-generation result.
	 *
	 * @param AIPS_Author_Post_Generation_Result $result Result object.
	 * @return self
	 */
	public static function from_post_result(AIPS_Author_Post_Generation_Result $result): self {
		switch ($result->get_status()) {
			case AIPS_Author_Post_Generation_Result::STATUS_SUCCESS:
				return new self(self::FULL_SUCCESS);
			case AIPS_Author_Post_Generation_Result::STATUS_PARTIAL:
				return new self(self::PARTIAL_SUCCESS);
			case AIPS_Author_Post_Generation_Result::STATUS_NO_WORK:
				return new self(self::NO_APPROVED_TOPICS);
			case AIPS_Author_Post_Generation_Result::STATUS_ALREADY_RUNNING:
				return new self(self::ALREADY_RUNNING);
			case AIPS_Author_Post_Generation_Result::STATUS_FAILED:
			default:
				return self::from_failures($result->get_failures());
		}
	}

	/**
	 * Classify an author topic-generation result.
	 *
	 * @param AIPS_Author_Topic_Generation_Result $result Result object.
	 * @return self
	 */
	public static function from_topic_result(AIPS_Author_Topic_Generation_Result $result): self {
		switch ($result->get_status()) {
			case AIPS_Author_Topic_Generation_Result::STATUS_SUCCESS:
				return new self(self::FULL_SUCCESS);
			case AIPS_Author_Topic_Generation_Result::STATUS_PARTIAL:
				return new self(self::PARTIAL_SUCCESS);
			case AIPS_Author_Topic_Generation_Result::STATUS_ALREADY_RUNNING:
				return new self(self::ALREADY_RUNNING);
			case AIPS_Author_Topic_Generation_Result::STATUS_NO_WORK:
				// No persisted topics and no hard error → the AI produced nothing
				// usable: a parsing/validation shortfall worth a bounded retry.
				$error = $result->get_error();
				if ($error instanceof WP_Error) {
					return self::from_wp_error($error);
				}
				return new self(self::PARSING_SHORTFALL);
			case AIPS_Author_Topic_Generation_Result::STATUS_FAILED:
			default:
				$error = $result->get_error();
				return $error instanceof WP_Error
					? self::from_wp_error($error)
					: new self(self::TRANSIENT_FAILURE);
		}
	}

	/**
	 * Classify a WP_Error into an outcome.
	 *
	 * @param WP_Error $error Error.
	 * @return self
	 */
	public static function from_wp_error(WP_Error $error): self {
		$code    = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		$data        = $error->get_error_data();
		$retry_after = is_array($data) && isset($data['retry_after']) ? (int) $data['retry_after'] : null;

		if ('already_running' === $code) {
			return new self(self::ALREADY_RUNNING, $code, $message);
		}

		if (in_array($code, array('no_topics', 'no_work', 'no_approved_topics'), true)) {
			return new self(self::NO_APPROVED_TOPICS, $code, $message);
		}

		if ('no_topics_parsed' === $code) {
			return new self(self::PARSING_SHORTFALL, $code, $message);
		}

		if ('rate_limit_exceeded' === $code) {
			return new self(self::RATE_LIMIT, $code, $message, $retry_after);
		}

		if (0 === strpos($code, 'db_') || 'database_error' === $code) {
			return new self(self::DB_FAILURE, $code, $message);
		}

		if (self::is_permanent_code($code)) {
			return new self(self::PERMANENT_ERROR, $code, $message);
		}

		// Unknown/unmapped errors are treated as transient so they get a bounded
		// retry rather than silently advancing the schedule.
		return new self(self::TRANSIENT_FAILURE, $code, $message, $retry_after);
	}

	/**
	 * Derive an outcome from a set of per-topic failures.
	 *
	 * @param array<int, array> $failures Failure records.
	 * @return self
	 */
	private static function from_failures(array $failures): self {
		if (empty($failures)) {
			return new self(self::TRANSIENT_FAILURE);
		}

		$has_permanent = false;
		$has_db        = false;
		$has_transient = false;
		$last          = end($failures);

		foreach ($failures as $failure) {
			$code = isset($failure['error_code']) ? (string) $failure['error_code'] : '';
			if (0 === strpos($code, 'db_') || 'database_error' === $code) {
				$has_db = true;
			} elseif (self::is_permanent_code($code)) {
				$has_permanent = true;
			} else {
				$has_transient = true;
			}
		}

		$code    = isset($last['error_code']) ? (string) $last['error_code'] : '';
		$message = isset($last['error_message']) ? (string) $last['error_message'] : '';

		if ($has_db) {
			return new self(self::DB_FAILURE, $code, $message);
		}
		if ($has_transient) {
			return new self(self::TRANSIENT_FAILURE, $code, $message);
		}
		if ($has_permanent) {
			return new self(self::PERMANENT_ERROR, $code, $message);
		}

		return new self(self::TRANSIENT_FAILURE, $code, $message);
	}

	/**
	 * Whether an error code is a permanent (non-retryable) condition.
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	private static function is_permanent_code(string $code): bool {
		if ('' === $code) {
			return false;
		}

		if (in_array($code, self::PERMANENT_CODES, true)) {
			return true;
		}

		if (class_exists('AIPS_Resilience_Service')) {
			if (in_array($code, AIPS_Resilience_Service::NON_RETRYABLE_CODES, true)
				|| in_array($code, AIPS_Resilience_Service::PERMANENT_CAPABILITY_CODES, true)
				|| in_array($code, AIPS_Resilience_Service::IMMEDIATE_OPEN_CODES, true)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Outcome constant.
	 *
	 * @return string
	 */
	public function get_outcome(): string {
		return $this->outcome;
	}

	/**
	 * Machine error code, if any.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Human-readable message, if any.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->error_message;
	}

	/**
	 * Provider-supplied retry delay in seconds, if available.
	 *
	 * @return int|null
	 */
	public function get_retry_after() {
		return $this->retry_after;
	}

	/**
	 * Whether the recurring schedule should advance to its next occurrence.
	 *
	 * @return bool
	 */
	public function advances_schedule(): bool {
		switch ($this->outcome) {
			case self::FULL_SUCCESS:
			case self::PARTIAL_SUCCESS:
			case self::NO_APPROVED_TOPICS:
				return true;
			default:
				return false;
		}
	}

	/**
	 * Whether a bounded retry should be scheduled for this outcome.
	 *
	 * @return bool
	 */
	public function should_retry(): bool {
		switch ($this->outcome) {
			case self::ALREADY_RUNNING:
			case self::TRANSIENT_FAILURE:
			case self::RATE_LIMIT:
			case self::PARSING_SHORTFALL:
			case self::DB_FAILURE:
				return true;
			default:
				return false;
		}
	}

	/**
	 * Whether this outcome represents a successful (full or partial) run.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return self::FULL_SUCCESS === $this->outcome || self::PARTIAL_SUCCESS === $this->outcome;
	}

	/**
	 * Whether this outcome is a permanent error that should notify the admin.
	 *
	 * @return bool
	 */
	public function is_permanent(): bool {
		return self::PERMANENT_ERROR === $this->outcome;
	}
}
