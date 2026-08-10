<?php
/**
 * Normalize and validate structured author topic candidates.
 *
 * @package AI_Post_Scheduler
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class AIPS_Author_Topic_Candidate_Validator {
	const TITLE_MIN_LENGTH = 10;
	const TITLE_MAX_LENGTH = 160;
	const KEYWORD_MIN_LENGTH = 2;
	const KEYWORD_MAX_LENGTH = 60;
	const KEYWORD_MAX_COUNT = 8;

	/**
	 * Validate candidates and record a reason for every rejection.
	 *
	 * @param array $candidates      AI candidates.
	 * @param array $existing_titles Titles from every persisted topic status.
	 * @param array $accepted_titles Titles accepted by earlier refill attempts.
	 * @return array{accepted:array,rejections:array,counts:array}
	 */
	public function validate(array $candidates, array $existing_titles = array(), array $accepted_titles = array()): array {
		$accepted  = array();
		$rejections = array();
		$seen = array();
		foreach (array_merge($existing_titles, $accepted_titles) as $title) {
			$canonical = $this->canonicalize((string) $title);
			if ('' !== $canonical) {
				$seen[$canonical] = 'existing';
			}
		}

		$counts = array('returned' => count($candidates), 'invalid' => 0, 'exact_duplicates' => 0);
		foreach ($candidates as $candidate) {
			$title = isset($candidate['title']) ? $this->normalize_title($candidate['title']) : '';
			$length = $this->length($title);
			if ($length < self::TITLE_MIN_LENGTH) {
				$counts['invalid']++;
				$rejections[] = array('title' => $title, 'reason' => 'title_too_short');
				continue;
			}
			if ($length > self::TITLE_MAX_LENGTH) {
				$counts['invalid']++;
				$rejections[] = array('title' => $title, 'reason' => 'title_too_long');
				continue;
			}

			$canonical = $this->canonicalize($title);
			if (isset($seen[$canonical])) {
				$counts['exact_duplicates']++;
				$rejections[] = array(
					'title'  => $title,
					'reason' => 'existing' === $seen[$canonical] ? 'duplicate_existing' : 'duplicate_in_response',
				);
				continue;
			}

			$keywords = $this->normalize_keywords(isset($candidate['keywords']) && is_array($candidate['keywords']) ? $candidate['keywords'] : array());
			if (empty($keywords)) {
				$counts['invalid']++;
				$rejections[] = array('title' => $title, 'reason' => 'keywords_required');
				continue;
			}

			$seen[$canonical] = 'response';
			$accepted[] = array(
				'title'    => $title,
				'score'    => max(0, min(100, isset($candidate['score']) ? (int) $candidate['score'] : 50)),
				'keywords' => $keywords,
			);
		}

		$counts['accepted'] = count($accepted);
		return array('accepted' => $accepted, 'rejections' => $rejections, 'counts' => $counts);
	}

	private function normalize_title($title): string {
		$title = sanitize_text_field($title);
		return trim((string) preg_replace('/\s+/u', ' ', $title));
	}

	private function normalize_keywords(array $keywords): array {
		$normalized = array();
		foreach ($keywords as $keyword) {
			$keyword = trim((string) preg_replace('/\s+/u', ' ', sanitize_text_field($keyword)));
			$keyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
			$length = $this->length($keyword);
			if ($length < self::KEYWORD_MIN_LENGTH || $length > self::KEYWORD_MAX_LENGTH || isset($normalized[$keyword])) {
				continue;
			}
			$normalized[$keyword] = $keyword;
			if (count($normalized) >= self::KEYWORD_MAX_COUNT) {
				break;
			}
		}
		return array_values($normalized);
	}

	private function canonicalize(string $title): string {
		$title = function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
		return (string) preg_replace('/[\p{P}\p{S}\s]+/u', '', $title);
	}

	private function length(string $value): int {
		return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
	}
}
