<?php
/**
 * Database-free behavior tests for direct author-topic post generation.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Post_Generator_Unit extends WP_UnitTestCase {

	private function inject($object, $name, $value) {
		$reflection = new ReflectionClass($object);
		while ($reflection && !$reflection->hasProperty($name)) {
			$reflection = $reflection->getParentClass();
		}
		$property = $reflection->getProperty($name);
		$property->setAccessible(true);
		$property->setValue($object, $value);
	}

	public function test_direct_generation_refuses_topic_at_post_limit() {
		$topic  = (object) array('id' => 12, 'author_id' => 7, 'topic_title' => 'Limit reached');
		$author = (object) array('id' => 7, 'max_posts_per_topic' => 2);

		$topics = new class($topic) {
			private $topic;
			public function __construct($topic) { $this->topic = $topic; }
			public function get_by_id($id) { return $this->topic; }
			public function is_eligible_for_generation($topic_id, $max_posts) { return false; }
		};
		$authors = new class($author) {
			private $author;
			public function __construct($author) { $this->author = $author; }
			public function get_by_id($id) { return $this->author; }
		};
		$claims = new class {
			public $claim_calls = 0;
			public function claim_topic_post_generation($topic_id) { $this->claim_calls++; return 'token'; }
			public function release_claim($type, $topic_id, $token) { return true; }
		};

		$generator = new class extends AIPS_Author_Post_Generator {
			public $generated = false;
			public function __construct() {}
			public function generate_post_from_topic($topic, $author, $creation_method = 'manual') {
				$this->generated = true;
				return 99;
			}
		};
		$this->inject($generator, 'topics_repository', $topics);
		$this->inject($generator, 'authors_repository', $authors);
		$this->inject($generator, 'claims_repository', $claims);

		$result = $generator->generate_now(12);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('topic_post_limit_reached', $result->get_error_code());
		$this->assertFalse($generator->generated);
		$this->assertSame(0, $claims->claim_calls);
	}

	public function test_regeneration_replaces_old_generation_without_consuming_an_extra_slot() {
		$topic  = (object) array('id' => 12, 'author_id' => 7, 'topic_title' => 'Replacement');
		$author = (object) array('id' => 7, 'max_posts_per_topic' => 1);
		$topics = new class($topic) {
			private $topic;
			public function __construct($topic) { $this->topic = $topic; }
			public function get_by_id($id) { return $this->topic; }
			public function is_eligible_for_generation($topic_id, $max_posts) { return false; }
		};
		$authors = new class($author) {
			private $author;
			public function __construct($author) { $this->author = $author; }
			public function get_by_id($id) { return $this->author; }
		};
		$claims = new class {
			public function claim_topic_post_generation($topic_id) { return 'token'; }
			public function release_claim($type, $topic_id, $token) { return true; }
		};
		$logs = new class {
			public $replacements = array();
			public function has_generated_post($topic_id, $post_id) { return true; }
			public function mark_post_replaced($topic_id, $old_post_id, $new_post_id) {
				$this->replacements[] = array($topic_id, $old_post_id, $new_post_id);
				return true;
			}
		};
		$generator = new class extends AIPS_Author_Post_Generator {
			public function __construct() {}
			public function generate_post_from_topic($topic, $author, $creation_method = 'manual') { return 99; }
		};
		$this->inject($generator, 'topics_repository', $topics);
		$this->inject($generator, 'authors_repository', $authors);
		$this->inject($generator, 'claims_repository', $claims);
		$this->inject($generator, 'logs_repository', $logs);

		$result = $generator->regenerate_post(55, 12);

		$this->assertSame(99, $result);
		$this->assertSame(array(array(12, 55, 99)), $logs->replacements);
	}
}
