<?php
/**
 * Table Gateway Class
 *
 * Provides genuinely identical, safe, prepared CRUD operations
 * for simple database tables.
 *
 * @package AI_Post_Scheduler
 * @since 2.9.2
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class AIPS_Table_Gateway
 *
 * Composition-based database gateway to extract raw CRUD operations from simple
 * repositories while leaving domain logic, hooks, validation, and cache in concrete classes.
 */
class AIPS_Table_Gateway {

	/**
	 * @var wpdb WordPress database abstraction object.
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb Injectable WordPress database abstraction object.
	 */
	public function __construct($wpdb = null) {
		if ($wpdb === null) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	/**
	 * Sanitize table/column identifiers to protect against SQL injection.
	 *
	 * @param string $identifier Table or column name.
	 * @return string Sanitized identifier.
	 */
	private function sanitize_identifier(string $identifier): string {
		return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
	}

	/**
	 * Log database error.
	 *
	 * @param string $operation Operation name (e.g. 'insert', 'update_by_id').
	 * @param string $table     Table name.
	 * @return void
	 */
	private function log_db_error(string $operation, string $table) {
		if (!empty($this->wpdb->last_error)) {
			$message = sprintf(
				'Database error during %s on table %s: %s',
				$operation,
				$table,
				$this->wpdb->last_error
			);
			if (class_exists('AIPS_Logger')) {
				AIPS_Logger::instance()->error($message, array(
					'last_query' => $this->wpdb->last_query,
				));
			} else {
				error_log('[AI Post Scheduler] ' . $message);
			}
		}
	}

	/**
	 * Find a single record by ID.
	 *
	 * @param string $table       Table name (prefixed).
	 * @param string $primary_key Name of the primary key column.
	 * @param int    $id          Record ID.
	 * @return object|null Record object, or null if not found.
	 */
	public function find_by_id(string $table, string $primary_key, int $id) {
		$table       = $this->sanitize_identifier($table);
		$primary_key = $this->sanitize_identifier($primary_key);

		$query = $this->wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE `{$primary_key}` = %d",
			$id
		);

		$row = $this->wpdb->get_row( $query );

		if ( ! empty( $this->wpdb->last_error ) ) {
			$this->log_db_error( 'find_by_id', $table );
		}

		if ( empty( $row ) || ! is_object( $row ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Find all records matching criteria.
	 *
	 * @param string $table    Table name (prefixed).
	 * @param array  $criteria Key-value pairs for WHERE clause.
	 * @param array  $options  Extra query options: 'order_by' => 'name ASC', 'limit' => 20.
	 * @return array Array of record objects.
	 */
	public function find_all(string $table, array $criteria = array(), array $options = array()): array {
		$table = $this->sanitize_identifier($table);

		$where_clauses = array();
		$params        = array();

		foreach ($criteria as $column => $value) {
			$column = $this->sanitize_identifier($column);
			if ($value === null) {
				$where_clauses[] = "`{$column}` IS NULL";
			} else {
				$where_clauses[] = "`{$column}` = " . (is_int($value) || is_bool($value) ? '%d' : '%s');
				$params[]        = is_bool($value) ? ($value ? 1 : 0) : $value;
			}
		}

		$where_sql = '';
		if (!empty($where_clauses)) {
			$where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
		}

		$order_by_sql = '';
		if (!empty($options['order_by'])) {
			$order_by     = preg_replace('/[^a-zA-Z0-9_,.` ]/', '', $options['order_by']);
			$order_by_sql = "ORDER BY {$order_by}";
		}

		$limit_sql = '';
		if (isset($options['limit'])) {
			$limit     = absint($options['limit']);
			$limit_sql = "LIMIT {$limit}";
		}

		$query = "SELECT * FROM `{$table}` {$where_sql} {$order_by_sql} {$limit_sql}";

		if (!empty($params)) {
			$query = $this->wpdb->prepare($query, $params);
		}

		$results = $this->wpdb->get_results( $query );

		if ( ! empty( $this->wpdb->last_error ) ) {
			$this->log_db_error( 'find_all', $table );
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Insert a record.
	 *
	 * @param string $table   Table name (prefixed).
	 * @param array  $data    Key-value pairs of data to insert.
	 * @param array  $formats Optional formats for the values.
	 * @return int|false Inserted ID on success, false on failure.
	 */
	public function insert(string $table, array $data, array $formats = array()) {
		$table = $this->sanitize_identifier($table);

		$sanitized_data = array();
		foreach ($data as $key => $val) {
			$sanitized_data[$this->sanitize_identifier($key)] = $val;
		}

		$result = $this->wpdb->insert($table, $sanitized_data, $formats);

		if ($result === false) {
			$this->log_db_error('insert', $table);
			return false;
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update a record by ID.
	 *
	 * @param string $table       Table name (prefixed).
	 * @param string $primary_key Name of the primary key column.
	 * @param int    $id          Record ID.
	 * @param array  $data        Key-value pairs of data to update.
	 * @param array  $formats     Optional formats for the values.
	 * @return bool True on success (or if no values changed), false on failure.
	 */
	public function update_by_id(string $table, string $primary_key, int $id, array $data, array $formats = array()): bool {
		$table       = $this->sanitize_identifier($table);
		$primary_key = $this->sanitize_identifier($primary_key);

		$sanitized_data = array();
		foreach ($data as $key => $val) {
			$sanitized_data[$this->sanitize_identifier($key)] = $val;
		}

		$result = $this->wpdb->update(
			$table,
			$sanitized_data,
			array($primary_key => $id),
			$formats,
			array('%d')
		);

		if ($result === false) {
			$this->log_db_error('update_by_id', $table);
			return false;
		}

		return true;
	}

	/**
	 * Delete a record by ID.
	 *
	 * @param string $table       Table name (prefixed).
	 * @param string $primary_key Name of the primary key column.
	 * @param int    $id          Record ID.
	 * @return bool True on success (or if record was already missing), false on database failure.
	 */
	public function delete_by_id(string $table, string $primary_key, int $id): bool {
		$table       = $this->sanitize_identifier($table);
		$primary_key = $this->sanitize_identifier($primary_key);

		$result = $this->wpdb->delete(
			$table,
			array($primary_key => $id),
			array('%d')
		);

		if ($result === false) {
			$this->log_db_error('delete_by_id', $table);
			return false;
		}

		return true;
	}
}
