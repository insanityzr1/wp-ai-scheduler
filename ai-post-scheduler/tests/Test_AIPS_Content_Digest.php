<?php
/**
 * Tests for bounded stateless article context.
 *
 * @package AI_Post_Scheduler
 */
class Test_AIPS_Content_Digest extends WP_UnitTestCase {

	public function test_short_content_is_unchanged() {
		$digest = new AIPS_Content_Digest();

		$this->assertSame('Short article.', $digest->build('Short article.', 1000));
	}

	public function test_long_content_keeps_outline_and_conclusion_within_budget() {
		$content = '<p>' . str_repeat('Opening context. ', 100) . '</p>'
			. '<h2>Important Findings</h2>'
			. '<p>' . str_repeat('Middle detail. ', 100) . '</p>'
			. '<h2>Final Recommendation</h2>'
			. '<p>' . str_repeat('Concluding evidence. ', 100) . '</p>';
		$digest = (new AIPS_Content_Digest())->build($content, 1200);

		$this->assertStringContainsString('ARTICLE BEGINNING:', $digest);
		$this->assertStringContainsString('ARTICLE OUTLINE:', $digest);
		$this->assertStringContainsString('Important Findings', $digest);
		$this->assertStringContainsString('ARTICLE CONCLUSION:', $digest);
		$this->assertLessThanOrEqual(1200, mb_strlen($digest));
	}
}
