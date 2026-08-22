<?php
/**
 * Tests for AIPS_Table_Gateway
 *
 * @package AI_Post_Scheduler
 */

class Mock_WPDB_Stateful_Gateway {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	public $last_query = '';
	private $data = array();

	public function prepare($query, ...$args) {
		if (empty($args)) {
			return $query;
		}
		if (count($args) === 1 && is_array($args[0])) {
			$args = $args[0];
		}
		foreach ($args as $arg) {
			$query = preg_replace('/%[sd]/', is_numeric($arg) ? $arg : "'$arg'", $query, 1);
		}
		$this->last_query = $query;
		return $query;
	}

	public function insert($table, $data, $format = null) {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$this->data[$table][$this->insert_id] = (object) $data;
		return true;
	}

	public function update($table, $data, $where, $format = null, $where_format = null) {
		if (!isset($this->data[$table])) {
			return false;
		}

		$id = $where['id'];
		if (isset($this->data[$table][$id])) {
			foreach ($data as $key => $value) {
				$this->data[$table][$id]->$key = $value;
			}
			return true;
		}
		return false;
	}

	public function delete($table, $where, $where_format = null) {
		if (!isset($this->data[$table])) {
			return false;
		}

		$id = $where['id'];
		if (isset($this->data[$table][$id])) {
			unset($this->data[$table][$id]);
			return true;
		}
		return false;
	}

	public function get_row($query, $output = OBJECT, $y = 0) {
		$this->last_query = $query;
		if (preg_match('/WHERE id = (\d+)/', $query, $matches)) {
			$id = (int) $matches[1];
			foreach ($this->data as $table => $rows) {
				if (isset($rows[$id])) {
					return $rows[$id];
				}
			}
		}
		return null;
	}

	public function get_results($query, $output = OBJECT) {
		$this->last_query = $query;
		$table_name = null;
		foreach (array_keys($this->data) as $t) {
			if (strpos($query, $t) !== false) {
				$table_name = $t;
				break;
			}
		}

		if (!$table_name || !isset($this->data[$table_name])) {
			return array();
		}

		$results = array_values($this->data[$table_name]);

		if (strpos($query, 'is_active = 1') !== false) {
			$results = array_filter($results, function($row) {
				return isset($row->is_active) && $row->is_active == 1;
			});
		}

		return array_values($results);
	}
}

class AIPS_Table_Gateway_Test extends WP_UnitTestCase {

	private $gateway;
	private $mock_wpdb;

	public function setUp(): void {
		parent::setUp();
		$this->mock_wpdb = new Mock_WPDB_Stateful_Gateway();
		$this->gateway   = new AIPS_Table_Gateway($this->mock_wpdb);
	}

	public function test_insert_returns_insert_id() {
		$data = array(
			'name'      => 'Test Record',
			'is_active' => 1,
		);

		$insert_id = $this->gateway->insert('wp_test_table', $data);

		$this->assertIsInt($insert_id);
		$this->assertEquals(1, $insert_id);
	}

	public function test_find_by_id_returns_correct_record() {
		$data = array(
			'name'      => 'Test Record Find',
			'is_active' => 1,
		);

		$insert_id = $this->gateway->insert('wp_test_table', $data);
		$record    = $this->gateway->find_by_id('wp_test_table', 'id', $insert_id);

		$this->assertNotNull($record);
		$this->assertEquals('Test Record Find', $record->name);
		$this->assertEquals(1, $record->is_active);
	}

	public function test_find_by_id_returns_null_when_not_found() {
		$record = $this->gateway->find_by_id('wp_test_table', 'id', 999);
		$this->assertNull($record);
	}

	public function test_update_by_id_updates_values() {
		$data = array(
			'name'      => 'Original Name',
			'is_active' => 1,
		);

		$insert_id = $this->gateway->insert('wp_test_table', $data);
		$updated   = $this->gateway->update_by_id('wp_test_table', 'id', $insert_id, array('name' => 'Updated Name'));

		$this->assertTrue($updated);

		$record = $this->gateway->find_by_id('wp_test_table', 'id', $insert_id);
		$this->assertEquals('Updated Name', $record->name);
	}

	public function test_delete_by_id_removes_record() {
		$data = array(
			'name'      => 'To Delete',
			'is_active' => 1,
		);

		$insert_id = $this->gateway->insert('wp_test_table', $data);
		$deleted   = $this->gateway->delete_by_id('wp_test_table', 'id', $insert_id);

		$this->assertTrue($deleted);

		$record = $this->gateway->find_by_id('wp_test_table', 'id', $insert_id);
		$this->assertNull($record);
	}

	public function test_find_all_with_criteria_and_order() {
		$this->gateway->insert('wp_test_table', array('name' => 'Record A', 'is_active' => 1));
		$this->gateway->insert('wp_test_table', array('name' => 'Record B', 'is_active' => 0));

		$active_records = $this->gateway->find_all('wp_test_table', array('is_active' => 1));
		$this->assertCount(1, $active_records);
		$this->assertEquals('Record A', $active_records[0]->name);
	}
}
