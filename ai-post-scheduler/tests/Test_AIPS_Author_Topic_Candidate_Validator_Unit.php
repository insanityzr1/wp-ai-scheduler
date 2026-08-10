<?php
/**
 * Unit tests for structured author topic candidate validation.
 *
 * @package AI_Post_Scheduler
 */

class Test_AIPS_Author_Topic_Candidate_Validator_Unit extends WP_UnitTestCase {
	public function test_normalizes_scores_keywords_and_multibyte_titles() {
		$validator = new AIPS_Author_Topic_Candidate_Validator();
		$result = $validator->validate(array(array(
			'title' => "  Estrategias   prácticas para crear contenido multilingüe  ",
			'score' => 145,
			'keywords' => array('SEO', 'seo', ' Contenido ', 'x', str_repeat('a', 70)),
		)));

		$this->assertCount(1, $result['accepted']);
		$this->assertSame('Estrategias prácticas para crear contenido multilingüe', $result['accepted'][0]['title']);
		$this->assertSame(100, $result['accepted'][0]['score']);
		$this->assertSame(array('seo', 'contenido'), $result['accepted'][0]['keywords']);
	}

	public function test_rejects_invalid_and_exact_duplicates_with_reasons() {
		$validator = new AIPS_Author_Topic_Candidate_Validator();
		$result = $validator->validate(
			array(
				array('title' => 'Too short', 'score' => 50, 'keywords' => array('one')),
				array('title' => 'A distinct practical guide to editorial planning', 'score' => 80, 'keywords' => array('Planning')),
				array('title' => 'A distinct practical guide to editorial planning!', 'score' => 70, 'keywords' => array('Other')),
				array('title' => 'An existing published topic with the same angle', 'score' => 60, 'keywords' => array('Existing')),
			),
			array('An existing published topic with the same angle')
		);

		$this->assertCount(1, $result['accepted']);
		$this->assertSame(1, $result['counts']['invalid']);
		$this->assertSame(2, $result['counts']['exact_duplicates']);
		$this->assertSame(array('title_too_short', 'duplicate_in_response', 'duplicate_existing'), array_column($result['rejections'], 'reason'));
	}
}
