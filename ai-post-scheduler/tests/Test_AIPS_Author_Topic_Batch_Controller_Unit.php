<?php
/**
 * Database-free AJAX contract tests for author-topic batches.
 *
 * @package AI_Post_Scheduler
 */

class AIPS_Unit_Ajax_Response_Exception extends RuntimeException {
	public $kind;
	public $payload;
	public function __construct($kind, $payload = null) { parent::__construct($kind); $this->kind = $kind; $this->payload = $payload; }
}

class AIPS_Ajax_Response {
	public static function success($data = null, $message = '') { throw new AIPS_Unit_Ajax_Response_Exception('success', $data); }
	public static function error($data = null, $code = null) { throw new AIPS_Unit_Ajax_Response_Exception('error', $data); }
	public static function permission_denied($message = '') { throw new AIPS_Unit_Ajax_Response_Exception('permission', $message); }
}

class Test_AIPS_Author_Topic_Batch_Controller_Unit extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$GLOBALS['aips_unit_nonce_valid'] = true;
		$GLOBALS['aips_unit_can_manage'] = true;
	}

	private function service($result) {
		return new class($result) {
			public $result;
			public $received = array();
			public function __construct($result) { $this->result = $result; }
			public function enqueue($ids, $key) { $this->received = array($ids, $key); return $this->result; }
			public function get_status($id) { $this->received = array($id); return $this->result; }
			public function cancel($id) { $this->received = array($id); return $this->result; }
		};
	}

	public function test_rejects_invalid_nonce_before_service_call() {
		$GLOBALS['aips_unit_nonce_valid'] = false;
		$controller = new AIPS_Author_Topic_Batch_Controller($this->service(array()));
		try { $controller->ajax_enqueue(); $this->fail('Expected AJAX response.'); } catch (AIPS_Unit_Ajax_Response_Exception $e) { $this->assertSame('error', $e->kind); }
	}

	public function test_rejects_user_without_manage_options() {
		$GLOBALS['aips_unit_can_manage'] = false;
		$controller = new AIPS_Author_Topic_Batch_Controller($this->service(array()));
		try { $controller->ajax_status(); $this->fail('Expected AJAX response.'); } catch (AIPS_Unit_Ajax_Response_Exception $e) { $this->assertSame('permission', $e->kind); }
	}

	public function test_enqueue_sanitizes_ids_and_returns_success_envelope() {
		$_POST = array('author_ids' => array('3', '2', '3', 'bad'), 'request_key' => ' request-key ');
		$service = $this->service(array('batch_id' => 'batch-1', 'status' => 'pending'));
		$controller = new AIPS_Author_Topic_Batch_Controller($service);
		try { $controller->ajax_enqueue(); $this->fail('Expected AJAX response.'); } catch (AIPS_Unit_Ajax_Response_Exception $e) {
			$this->assertSame('success', $e->kind);
			$this->assertSame(array(3, 2), $service->received[0]);
			$this->assertSame('request-key', $service->received[1]);
			$this->assertSame('batch-1', $e->payload['batch_id']);
		}
	}

	public function test_status_propagates_service_error() {
		$_POST = array('batch_id' => 'missing');
		$controller = new AIPS_Author_Topic_Batch_Controller($this->service(new WP_Error('batch_not_found', 'Missing batch')));
		try { $controller->ajax_status(); $this->fail('Expected AJAX response.'); } catch (AIPS_Unit_Ajax_Response_Exception $e) {
			$this->assertSame('error', $e->kind);
			$this->assertSame('batch_not_found', $e->payload['code']);
		}
	}

	public function test_cancel_returns_terminal_contract() {
		$_POST = array('batch_id' => 'batch-1');
		$controller = new AIPS_Author_Topic_Batch_Controller($this->service(true));
		try { $controller->ajax_cancel(); $this->fail('Expected AJAX response.'); } catch (AIPS_Unit_Ajax_Response_Exception $e) {
			$this->assertSame('success', $e->kind);
			$this->assertSame(array('batch_id' => 'batch-1', 'status' => 'canceled'), $e->payload);
		}
	}
}
