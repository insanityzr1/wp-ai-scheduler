<?php
/**
 * Database-free topic refill tests.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Topic_Refill_Unit extends WP_UnitTestCase {
	public function test_topic_prompt_builder_builds_constrained_refill_prompt() {
		$builder = (new ReflectionClass(AIPS_Prompt_Builder_Topic::class))->newInstanceWithoutConstructor();
		$prompt = $builder->build_refill(
			'Base prompt.',
			2,
			array('Accepted topic title'),
			array(array('title' => 'Rejected topic title', 'reason' => 'duplicate_existing'))
		);

		$this->assertStringContainsString('exactly 2 replacement', $prompt);
		$this->assertStringContainsString('Accepted topic title', $prompt);
		$this->assertStringContainsString('Rejected topic title (duplicate_existing)', $prompt);
	}

	public function test_refills_only_missing_candidates_and_reports_quality_metrics() {
		$ai = new class implements AIPS_AI_Service_Interface {
			public $prompts = array();
			private $responses = array(
				array(
					array('title' => 'A useful original topic', 'score' => 82, 'keywords' => array('Useful', 'Original')),
					array('title' => 'An existing stored topic', 'score' => 70, 'keywords' => array('Existing')),
					array('title' => 'Short', 'score' => 60, 'keywords' => array('Short')),
				),
				array(
					array('title' => 'A second replacement topic', 'score' => 78, 'keywords' => array('Second')),
					array('title' => 'A third replacement topic', 'score' => 76, 'keywords' => array('Third')),
				),
			);
			public function is_available() { return true; }
			public function generate_text($prompt, $options = array()) { return ''; }
			public function generate_json($prompt, $options = array()) { $this->prompts[] = array($prompt, $options); return array_shift($this->responses); }
			public function generate_image($prompt, $options = array()) { return ''; }
			public function generate_embedding($text, $options = array()) { return array(); }
			public function supports_embeddings() { return false; }
			public function supports_conversation() { return false; }
			public function get_call_log() { return array(); }
		};

		$repository = new class {
			public $created = array();
			public function get_approved_summary($author_id, $limit) { return array(); }
			public function get_rejected_summary($author_id, $limit) { return array(); }
			public function get_by_author($author_id) { return array((object) array('topic_title' => 'An existing stored topic', 'metadata' => '')); }
			public function create_bulk($topics, $run_id) { $this->created = $topics; return array(101, 102, 103); }
			public function get_by_run_id($run_id, $author_id) {
				$rows = array();
				foreach ($this->created as $index => $topic) {
					$rows[] = (object) array_merge(array('id' => 101 + $index), $topic);
				}
				return $rows;
			}
		};
		$logger = new class implements AIPS_Logger_Interface {
			public function log($message, $level = 'info', $context = array()) {}
			public function addSeparator($text) {}
		};
		$embeddings = new class {
			public function is_embeddings_supported() { return false; }
		};
		$feedback = new class {
			public function get_reason_category_statistics($author_id) { return array(); }
		};
		$prompt_builder = new class {
			public function build($author, $approved, $rejected, $guidance) { return 'Generate author topics.'; }
			public function build_refill($base_prompt, $missing, $accepted, $rejections) {
				$titles = array_map(function($rejection) { return $rejection['title']; }, $rejections);
				return $base_prompt . ' Generate exactly ' . $missing . ' replacement topics. Accepted: ' . implode(', ', $accepted) . '. Rejected: ' . implode(', ', $titles);
			}
		};

		$generator = new AIPS_Author_Topics_Generator($ai, $logger, $repository, new stdClass(), $embeddings, $feedback, $prompt_builder, new AIPS_Author_Topic_Candidate_Validator());
		$author = (object) array('id' => 7, 'name' => 'Author', 'field_niche' => 'Testing', 'topic_generation_quantity' => 3);
		$result = $generator->generate_topics_with_result($author);
		$data = $result->to_array();

		$this->assertSame('success', $data['status']);
		$this->assertSame(3, $data['persisted_count']);
		$this->assertSame(1, $data['refill_attempts']);
		$this->assertSame(1, $data['quality']['invalid']);
		$this->assertSame(1, $data['quality']['exact_duplicates']);
		$this->assertSame(2, $ai->prompts[1][1]['json_schema']['maxItems']);
		$this->assertStringContainsString('A useful original topic', $ai->prompts[1][0]);
		$this->assertStringContainsString('An existing stored topic', $ai->prompts[1][0]);
	}
}
