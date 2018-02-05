<?php
/*
** Zabbix
** Copyright (C) 2001-2018 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


/**
 * Class containing methods for operations with events.
 */
class CEvent extends CApiService {

	protected $tableName = 'events';
	protected $tableAlias = 'e';
	protected $sortColumns = ['eventid', 'objectid', 'clock'];

	/**
	 * Array of supported objects where keys are object IDs and values are translated object names.
	 *
	 * @var array
	 */
	protected $objects = [];

	/**
	 * Array of supported sources where keys are source IDs and values are translated source names.
	 *
	 * @var array
	 */
	protected $sources = [];

	public function __construct() {
		parent::__construct();

		$this->sources = eventSource();
		$this->objects = eventObject();
	}

	/**
	 * Get events data.
	 *
	 * @param _array $options
	 * @param array $options['itemids']
	 * @param array $options['hostids']
	 * @param array $options['groupids']
	 * @param array $options['eventids']
	 * @param array $options['applicationids']
	 * @param array $options['status']
	 * @param bool  $options['editable']
	 * @param array $options['count']
	 * @param array $options['pattern']
	 * @param array $options['limit']
	 * @param array $options['order']
	 *
	 * @return array|int item data as array or false if error
	 */
	public function get($options = []) {
		$result = [];

		$sqlParts = [
			'select'	=> [$this->fieldId('eventid')],
			'from'		=> ['e' => 'events e'],
			'where'		=> [],
			'order'		=> [],
			'group'		=> [],
			'limit'		=> null
		];

		$defOptions = [
			'eventids'					=> null,
			'groupids'					=> null,
			'hostids'					=> null,
			'applicationids'			=> null,
			'objectids'					=> null,

			'editable'					=> false,
			'object'					=> EVENT_OBJECT_TRIGGER,
			'source'					=> EVENT_SOURCE_TRIGGERS,
			'severities'				=> null,
			'nopermissions'				=> null,
			// filter
			'value'						=> null,
			'time_from'					=> null,
			'time_till'					=> null,
			'eventid_from'				=> null,
			'eventid_till'				=> null,
			'acknowledged'				=> null,
			'evaltype'					=> TAG_EVAL_TYPE_AND,
			'tags'						=> null,
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> false,
			'excludeSearch'				=> false,
			'searchWildcardsEnabled'	=> null,
			// output
			'output'					=> API_OUTPUT_EXTEND,
			'selectHosts'				=> null,
			'selectRelatedObject'		=> null,
			'select_alerts'				=> null,
			'select_acknowledges'		=> null,
			'selectTags'				=> null,
			'countOutput'				=> false,
			'groupCount'				=> false,
			'preservekeys'				=> false,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null
		];
		$options = zbx_array_merge($defOptions, $options);

		$this->validateGet($options);

		// source and object
		$sqlParts['where'][] = 'e.source='.zbx_dbstr($options['source']);
		$sqlParts['where'][] = 'e.object='.zbx_dbstr($options['object']);

		// editable + PERMISSION CHECK
		$fillter_condition = [];
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				// specific triggers
				$user_groups = getUserGroupsByUserId(self::$userData['userid']);

				if ($options['objectids'] !== null) {
					$triggers = API::Trigger()->get([
						'output' => ['triggerid'],
						'selectGroups' => ['groupid'],
						'triggerids' => $options['objectids'],
						'editable' => $options['editable']
					]);

					$group_triggers = [];

					foreach ($triggers as $trigger) {
						foreach ($trigger['groups'] as $group) {
							$group_triggers[$group['groupid']][$trigger['triggerid']] = $trigger['triggerid'];
						}
					}

					list($tag_filters, $full_access_groups)
							= $this->calculateTagFilterRestriction($user_groups, array_keys($group_triggers));

					// Add condition to select events that must match host group only.
					if ($full_access_groups) {
						$allowed_triggers = [];

						foreach ($full_access_groups as $groupid) {
							if (array_key_exists($groupid, $group_triggers)) {
								$allowed_triggers = array_merge($allowed_triggers, $group_triggers[$groupid]);
							}
						}

						if ($allowed_triggers) {
							$fillter_condition[] = dbConditionInt('e.objectid', $allowed_triggers);
						}
					}

					// Add condition to select events that are filtered by tag filter.
					foreach ($tag_filters as $groupid => $tag_filter) {
						foreach ($tag_filter as $values) {
							if (array_key_exists($groupid, $group_triggers)) {
								$tag_value = '';
								if ($values['value'] !== '') {
									$tag_value = ' AND et.value = '.zbx_dbstr($values['value']);
								}

								$fillter_condition[] = 'EXISTS ('.
									'SELECT NULL'.
									' FROM event_tag et'.
									' WHERE et.eventid = e.eventid'.
										' AND '.dbConditionInt('e.objectid', $group_triggers[$groupid]).
										' AND et.tag = '.zbx_dbstr($values['tag']).
										$tag_value.
								')';
							}
						}
					}

					/**
					 * At this point empty $fillter_condition means that user has no access to any of triggers or host
					 * groups.
					 */
					if (!$fillter_condition) {
						$options['objectids'] = [];
					}
				}
				// all triggers
				else {
					// Get all visible groups.
					$host_groups = API::HostGroup()->get([
						'output' => [],
						'preservekeys' => true
					]);

					list($tag_filters, $full_access_groups)
						= $this->calculateTagFilterRestriction($user_groups, array_keys($host_groups));

					if ($host_groups) {
						$triggers = API::Trigger()->get([
							'output' => ['triggerid'],
							'selectGroups' => ['groupid'],
							'groupids' => array_keys($host_groups)
						]);

						$group_triggers = [];

						foreach ($triggers as $trigger) {
							foreach ($trigger['groups'] as $group) {
								$group_triggers[$group['groupid']][$trigger['triggerid']] = $trigger['triggerid'];
							}
						}

						// Add condition to select events that are filtered by tag filter.
						foreach ($tag_filters as $groupid => $tag_filter) {
							foreach ($tag_filter as $values) {
								if (array_key_exists($groupid, $group_triggers)) {
									$tag_value = '';
									if ($values['value'] !== '') {
										$tag_value = ' AND et.value = '.zbx_dbstr($values['value']);
									}

									$fillter_condition[] = 'EXISTS ('.
										'SELECT NULL'.
										' FROM event_tag et'.
										' WHERE et.eventid = e.eventid'.
											' AND '.dbConditionInt('e.objectid', $group_triggers[$groupid]).
											' AND et.tag = '.zbx_dbstr($values['tag']).
											$tag_value.
									')';
								}
							}
						}
					}

					// Add condition to select events that must match host group only.
					if ($full_access_groups) {
						$fillter_condition[] = 'EXISTS ('.
						'SELECT NULL'.
						' FROM functions f,items i,hosts_groups hgg'.
						' WHERE e.objectid=f.triggerid'.
							' AND f.itemid=i.itemid'.
							' AND i.hostid=hgg.hostid'.
							' AND '.dbConditionInt('hgg.groupid', $full_access_groups).
						')';
					}

					/**
					 * At this point empty $fillter_condition means that user has no access to any of triggers or host
					 * groups.
					 */
					if (!$fillter_condition) {
						$options['objectids'] = [];
					}
				}
			}
			// items and LLD rules
			elseif ($options['object'] == EVENT_OBJECT_ITEM || $options['object'] == EVENT_OBJECT_LLDRULE) {
				// specific items or LLD rules
				if ($options['objectids'] !== null) {
					if ($options['object'] == EVENT_OBJECT_ITEM) {
						$items = API::Item()->get([
							'output' => ['itemid'],
							'itemids' => $options['objectids'],
							'editable' => $options['editable']
						]);
						$options['objectids'] = zbx_objectValues($items, 'itemid');
					}
					elseif ($options['object'] == EVENT_OBJECT_LLDRULE) {
						$items = API::DiscoveryRule()->get([
							'output' => ['itemid'],
							'itemids' => $options['objectids'],
							'editable' => $options['editable']
						]);
						$options['objectids'] = zbx_objectValues($items, 'itemid');
					}
				}
				// all items and LLD rules
				else {
					$userGroups = getUserGroupsByUserId(self::$userData['userid']);

					$sqlParts['where'][] = 'EXISTS ('.
							'SELECT NULL'.
							' FROM items i,hosts_groups hgg'.
								' JOIN rights r'.
									' ON r.id=hgg.groupid'.
										' AND '.dbConditionInt('r.groupid', $userGroups).
							' WHERE e.objectid=i.itemid'.
								' AND i.hostid=hgg.hostid'.
							' GROUP BY hgg.hostid'.
							' HAVING MIN(r.permission)>'.PERM_DENY.
								' AND MAX(r.permission)>='.($options['editable'] ? PERM_READ_WRITE : PERM_READ).
							')';
				}
			}
		}

		// objectids
		if ($options['objectids'] !== null
				&& in_array($options['object'], [EVENT_OBJECT_TRIGGER, EVENT_OBJECT_ITEM, EVENT_OBJECT_LLDRULE])) {

			zbx_value2array($options['objectids']);
			$sqlParts['where'][] = dbConditionInt('e.objectid', $options['objectids']);

			if ($options['groupCount']) {
				$sqlParts['group']['objectid'] = 'e.objectid';
			}
		}

		// groupids
		if ($options['groupids'] !== null) {
			zbx_value2array($options['groupids']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['f'] = 'functions f';
				$sqlParts['from']['i'] = 'items i';
				$sqlParts['from']['hg'] = 'hosts_groups hg';
				$sqlParts['where']['e-f'] = 'e.objectid=f.triggerid';
				$sqlParts['where']['f-i'] = 'f.itemid=i.itemid';
				$sqlParts['where']['i-hg'] = 'i.hostid=hg.hostid';
				$sqlParts['where']['hg'] = dbConditionInt('hg.groupid', $options['groupids']);
			}
			// lld rules and items
			elseif ($options['object'] == EVENT_OBJECT_LLDRULE || $options['object'] == EVENT_OBJECT_ITEM) {
				$sqlParts['from']['i'] = 'items i';
				$sqlParts['from']['hg'] = 'hosts_groups hg';
				$sqlParts['where']['e-i'] = 'e.objectid=i.itemid';
				$sqlParts['where']['i-hg'] = 'i.hostid=hg.hostid';
				$sqlParts['where']['hg'] = dbConditionInt('hg.groupid', $options['groupids']);
			}
		}

		// hostids
		if ($options['hostids'] !== null) {
			zbx_value2array($options['hostids']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['f'] = 'functions f';
				$sqlParts['from']['i'] = 'items i';
				$sqlParts['where']['e-f'] = 'e.objectid=f.triggerid';
				$sqlParts['where']['f-i'] = 'f.itemid=i.itemid';
				$sqlParts['where']['i'] = dbConditionInt('i.hostid', $options['hostids']);
			}
			// lld rules and items
			elseif ($options['object'] == EVENT_OBJECT_LLDRULE || $options['object'] == EVENT_OBJECT_ITEM) {
				$sqlParts['from']['i'] = 'items i';
				$sqlParts['where']['e-i'] = 'e.objectid=i.itemid';
				$sqlParts['where']['i'] = dbConditionInt('i.hostid', $options['hostids']);
			}
		}

		// applicationids
		if ($options['applicationids'] !== null) {
			zbx_value2array($options['applicationids']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['f'] = 'functions f';
				$sqlParts['from']['ia'] = 'items_applications ia';
				$sqlParts['where']['e-f'] = 'e.objectid=f.triggerid';
				$sqlParts['where']['f-ia'] = 'f.itemid=ia.itemid';
				$sqlParts['where']['ia'] = dbConditionInt('ia.applicationid', $options['applicationids']);
			}
			// items
			elseif ($options['object'] == EVENT_OBJECT_ITEM) {
				$sqlParts['from']['ia'] = 'items_applications ia';
				$sqlParts['where']['e-ia'] = 'e.objectid=ia.itemid';
				$sqlParts['where']['ia'] = dbConditionInt('ia.applicationid', $options['applicationids']);
			}
			// ignore this filter for lld rules
		}

		// severities
		if ($options['severities'] !== null) {
			zbx_value2array($options['severities']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['t'] = 'triggers t';
				$sqlParts['where']['e-t'] = 'e.objectid=t.triggerid';
				$sqlParts['where']['t'] = dbConditionInt('t.priority', $options['severities']);
			}
			// ignore this filter for items and lld rules
		}

		// acknowledged
		if (!is_null($options['acknowledged'])) {
			$sqlParts['where'][] = 'e.acknowledged='.($options['acknowledged'] ? 1 : 0);
		}

		// tags
		if ($options['tags'] !== null && $options['tags']) {
			$where = '';
			$cnt = count($options['tags']);

			foreach ($options['tags'] as $tag) {
				if (!array_key_exists('value', $tag)) {
					$tag['value'] = '';
				}

				if ($tag['value'] !== '') {
					if (!array_key_exists('operator', $tag)) {
						$tag['operator'] = TAG_OPERATOR_LIKE;
					}

					switch ($tag['operator']) {
						case TAG_OPERATOR_EQUAL:
							$tag['value'] = ' AND et.value='.zbx_dbstr($tag['value']);
							break;

						case TAG_OPERATOR_LIKE:
						default:
							$tag['value'] = str_replace('!', '!!', $tag['value']);
							$tag['value'] = str_replace('%', '!%', $tag['value']);
							$tag['value'] = str_replace('_', '!_', $tag['value']);
							$tag['value'] = '%'.mb_strtoupper($tag['value']).'%';
							$tag['value'] = ' AND UPPER(et.value) LIKE'.zbx_dbstr($tag['value'])." ESCAPE '!'";
					}
				}
				elseif ($tag['operator'] == TAG_OPERATOR_EQUAL) {
					$tag['value'] = ' AND et.value='.zbx_dbstr($tag['value']);
				}

				if ($where !== '')  {
					$where .= ($options['evaltype'] == TAG_EVAL_TYPE_OR) ? ' OR ' : ' AND ';
				}

				$where .= 'EXISTS ('.
					'SELECT NULL'.
					' FROM event_tag et'.
					' WHERE e.eventid=et.eventid'.
						' AND et.tag='.zbx_dbstr($tag['tag']).$tag['value'].
				')';
			}

			// Add closing parenthesis if there are more than one OR statements.
			if ($options['evaltype'] == TAG_EVAL_TYPE_OR && $cnt > 1) {
				$where = '('.$where.')';
			}

			$sqlParts['where'][] = $where;
		}

		// time_from
		if ($options['time_from'] !== null) {
			$sqlParts['where'][] = 'e.clock>='.zbx_dbstr($options['time_from']);
		}

		// time_till
		if ($options['time_till'] !== null) {
			$sqlParts['where'][] = 'e.clock<='.zbx_dbstr($options['time_till']);
		}

		// value
		if (!is_null($options['value'])) {
			zbx_value2array($options['value']);
			$sqlParts['where'][] = dbConditionInt('e.value', $options['value']);
		}

		// search
		if (is_array($options['search'])) {
			zbx_db_search('events e', $options, $sqlParts);
		}

		// filter
		if (is_array($options['filter'])) {
			$this->dbFilter('events e', $options, $sqlParts);
		}

		// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sqlParts['limit'] = $options['limit'];
		}

		/**
		 * Apply $fillter_condition to select accessible eventids. This should be done separately to get list of
		 * accessible eventids and create additional condition to include into results not only accessible events but
		 * also recovery events of accessible events that should not be accessible otherwise due the applied tag
		 * filters.
		 */
		if ($fillter_condition) {
			// Store current state of $sqlParts for later use.
			$sql_parts_tmp = $sqlParts;

			// Build version of query to select accessible eventids only.
			$sqlParts['select'] = ['e.eventid'];
			$sqlParts['where'][] = '('.implode(' OR ', $fillter_condition).')';

			$sqlParts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
			$res = DBselect($this->createSelectQueryFromParts($sqlParts), $sqlParts['limit']);

			while ($event = DBfetch($res)) {
				$accessible_eventids[$event['eventid']] = true;
			}

			// Set $sql_parts_tmp back to original $sqlParts.
			$sqlParts = $sql_parts_tmp;
			unset($sql_parts_tmp);

			if ($accessible_eventids) {
				$sqlParts['where'][] = '('.
					dbConditionInt('e.eventid', array_keys($accessible_eventids)).
					' OR EXISTS ('.
						' SELECT NULL'.
						' FROM event_recovery er2'.
						' WHERE er2.r_eventid=e.eventid'.
							' AND '.dbConditionInt('er2.eventid', array_keys($accessible_eventids)).
					')'.
				')';
			}
		}

		// eventids
		if (!is_null($options['eventids'])) {
			zbx_value2array($options['eventids']);
			$sqlParts['where'][] = dbConditionInt('e.eventid', $options['eventids']);
		}

		// eventid_from
		if ($options['eventid_from'] !== null) {
			$sqlParts['where'][] = 'e.eventid>='.zbx_dbstr($options['eventid_from']);
		}

		// eventid_till
		if ($options['eventid_till'] !== null) {
			$sqlParts['where'][] = 'e.eventid<='.zbx_dbstr($options['eventid_till']);
		}

		$sqlParts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$sqlParts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$res = DBselect($this->createSelectQueryFromParts($sqlParts), $sqlParts['limit']);
		while ($event = DBfetch($res)) {
			if ($options['countOutput']) {
				if ($options['groupCount']) {
					$result[] = $event;
				}
				else {
					$result = $event['rowscount'];
				}
			}
			else {
				$result[$event['eventid']] = $event;
			}
		}

		if ($options['countOutput']) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
			$result = $this->unsetExtraFields($result, ['object', 'objectid'], $options['output']);
		}

		// removing keys (hash -> array)
		if (!$options['preservekeys']) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Validates the input parameters for the get() method.
	 *
	 * @throws APIException     if the input is invalid
	 *
	 * @param array     $options
	 */
	protected function validateGet(array $options) {
		$sourceValidator = new CLimitedSetValidator([
			'values' => array_keys(eventSource())
		]);
		if (!$sourceValidator->validate($options['source'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect source value.'));
		}

		$objectValidator = new CLimitedSetValidator([
			'values' => array_keys(eventObject())
		]);
		if (!$objectValidator->validate($options['object'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect object value.'));
		}

		$sourceObjectValidator = new CEventSourceObjectValidator();
		if (!$sourceObjectValidator->validate(['source' => $options['source'], 'object' => $options['object']])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $sourceObjectValidator->getError());
		}

		$evaltype_validator = new CLimitedSetValidator([
			'values' => [TAG_EVAL_TYPE_AND, TAG_EVAL_TYPE_OR]
		]);
		if (!$evaltype_validator->validate($options['evaltype'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect evaltype value.'));
		}
	}

	/**
	 * Acknowledges the given events and closes them if necessary.
	 *
	 * @param array  $data					And array of event acknowledgement data.
	 * @param mixed  $data['eventids']		An event ID or an array of event IDs to acknowledge.
	 * @param string $data['message']		Acknowledgement message.
	 * @param int	 $data['action']		Close problem
	 *										Possible values are:
	 *											0x00 - ZBX_ACKNOWLEDGE_ACTION_NONE;
	 *											0x01 - ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM.
	 *
	 * @return array
	 */
	public function acknowledge(array $data) {
		$data['eventids'] = zbx_toArray($data['eventids']);

		$this->validateAcknowledge($data);

		$eventids = zbx_toHash($data['eventids']);

		if (!DBexecute('UPDATE events SET acknowledged=1 WHERE '.dbConditionInt('eventid', $eventids))) {
			self::exception(ZBX_API_ERROR_PARAMETERS, 'DBerror');
		}

		$time = time();
		$acknowledges = [];
		$action = array_key_exists('action', $data) ? $data['action'] : ZBX_ACKNOWLEDGE_ACTION_NONE;

		foreach ($eventids as $eventid) {
			$acknowledges[] = [
				'userid' => self::$userData['userid'],
				'eventid' => $eventid,
				'clock' => $time,
				'message' => $data['message'],
				'action' => $action
			];
		}

		$acknowledgeids = DB::insert('acknowledges', $acknowledges);

		$ack_count = count($acknowledgeids);

		if ($action == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
			// Close the problem manually.

			$tasks = [];

			for ($i = 0; $i < $ack_count; $i++) {
				$tasks[] = [
					'type' => ZBX_TM_TASK_CLOSE_PROBLEM,
					'status' => ZBX_TM_STATUS_NEW,
					'clock' => $time
				];
			}

			$taskids = DB::insert('task', $tasks);

			$task_close = [];

			for ($i = 0; $i < $ack_count; $i++) {
				$task_close[] = [
					'taskid' => $taskids[$i],
					'acknowledgeid' => $acknowledgeids[$i]
				];
			}

			DB::insert('task_close_problem', $task_close, false);
		}

		$tasks = [];

		for ($i = 0; $i < $ack_count; $i++) {
			$tasks[] = [
				'type' => ZBX_TM_TASK_ACKNOWLEDGE,
				'status' => ZBX_TM_STATUS_NEW,
				'clock' => $time
			];
		}

		$taskids = DB::insert('task', $tasks);

		$tasks_ack = [];

		for ($i = 0; $i < $ack_count; $i++) {
			$tasks_ack[] = [
				'taskid' => $taskids[$i],
				'acknowledgeid' => $acknowledgeids[$i]
			];
		}

		DB::insert('task_acknowledge', $tasks_ack, false);

		return ['eventids' => array_values($eventids)];
	}

	/**
	 * Validates the input parameters for the acknowledge() method.
	 *
	 * @throws APIException     if the input is invalid
	 *
	 * @param array     $data
	 */
	protected function validateAcknowledge(array $data) {
		$dbfields = ['eventids' => null, 'message' => null];

		if (!check_db_fields($dbfields, $data)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
		}

		if ($data['message'] === '') {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Incorrect value for field "%1$s": %2$s.', 'message', _('cannot be empty'))
			);
		}

		$this->checkCanBeAcknowledged($data['eventids']);

		if (array_key_exists('action', $data)) {
			if ($data['action'] != ZBX_ACKNOWLEDGE_ACTION_NONE
					&& $data['action'] != ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect value for field "%1$s": %2$s.',
					'action', _s('unexpected value "%1$s"', $data['action'])
				));
			}

			if ($data['action'] == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
				$this->checkCanBeManuallyClosed(array_unique($data['eventids']));
			}
		}
	}

	protected function applyQueryOutputOptions($tableName, $tableAlias, array $options, array $sqlParts) {
		$sqlParts = parent::applyQueryOutputOptions($tableName, $tableAlias, $options, $sqlParts);

		if (!$options['countOutput']) {
			if ($this->outputIsRequested('r_eventid', $options['output'])) {
				// Select fields from event_recovery table using LEFT JOIN.

				$sqlParts['select']['r_eventid'] = 'er1.r_eventid';
				$sqlParts['left_join'][] = ['from' => 'event_recovery er1', 'on' => 'er1.eventid=e.eventid'];
				$sqlParts['left_table'] = 'e';
			}

			if ($this->outputIsRequested('c_eventid', $options['output'])
					|| $this->outputIsRequested('correlationid', $options['output'])
					|| $this->outputIsRequested('userid', $options['output'])) {
				// Select fields from event_recovery table using LEFT JOIN.

				if ($this->outputIsRequested('c_eventid', $options['output'])) {
					$sqlParts['select']['c_eventid'] = 'er2.c_eventid';
				}
				if ($this->outputIsRequested('correlationid', $options['output'])) {
					$sqlParts['select']['correlationid'] = 'er2.correlationid';
				}
				if ($this->outputIsRequested('userid', $options['output'])) {
					$sqlParts['select']['userid'] = 'er2.userid';
				}

				$sqlParts['left_join'][] = ['from' => 'event_recovery er2', 'on' => 'er2.r_eventid=e.eventid'];
				$sqlParts['left_table'] = 'e';
			}

			if ($options['selectRelatedObject'] !== null || $options['selectHosts'] !== null) {
				$sqlParts = $this->addQuerySelect('e.object', $sqlParts);
				$sqlParts = $this->addQuerySelect('e.objectid', $sqlParts);
			}
		}

		return $sqlParts;
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$eventIds = array_keys($result);

		// adding hosts
		if ($options['selectHosts'] !== null && $options['selectHosts'] != API_OUTPUT_COUNT) {
			// trigger events
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$query = DBselect(
					'SELECT e.eventid,i.hostid'.
						' FROM events e,functions f,items i'.
						' WHERE '.dbConditionInt('e.eventid', $eventIds).
						' AND e.objectid=f.triggerid'.
						' AND f.itemid=i.itemid'.
						' AND e.object='.zbx_dbstr($options['object']).
						' AND e.source='.zbx_dbstr($options['source'])
				);
			}
			// item and LLD rule events
			elseif ($options['object'] == EVENT_OBJECT_ITEM || $options['object'] == EVENT_OBJECT_LLDRULE) {
				$query = DBselect(
					'SELECT e.eventid,i.hostid'.
						' FROM events e,items i'.
						' WHERE '.dbConditionInt('e.eventid', $eventIds).
						' AND e.objectid=i.itemid'.
						' AND e.object='.zbx_dbstr($options['object']).
						' AND e.source='.zbx_dbstr($options['source'])
				);
			}

			$relationMap = new CRelationMap();
			while ($relation = DBfetch($query)) {
				$relationMap->addRelation($relation['eventid'], $relation['hostid']);
			}

			$hosts = API::Host()->get([
				'output' => $options['selectHosts'],
				'hostids' => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true
			]);
			$result = $relationMap->mapMany($result, $hosts, 'hosts');
		}

		// adding the related object
		if ($options['selectRelatedObject'] !== null && $options['selectRelatedObject'] != API_OUTPUT_COUNT
				&& $options['object'] != EVENT_OBJECT_AUTOREGHOST) {

			$relationMap = new CRelationMap();
			foreach ($result as $event) {
				$relationMap->addRelation($event['eventid'], $event['objectid']);
			}

			switch ($options['object']) {
				case EVENT_OBJECT_TRIGGER:
					$api = API::Trigger();
					break;
				case EVENT_OBJECT_DHOST:
					$api = API::DHost();
					break;
				case EVENT_OBJECT_DSERVICE:
					$api = API::DService();
					break;
				case EVENT_OBJECT_ITEM:
					$api = API::Item();
					break;
				case EVENT_OBJECT_LLDRULE:
					$api = API::DiscoveryRule();
					break;
			}

			$objects = $api->get([
				'output' => $options['selectRelatedObject'],
				$api->pkOption() => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true
			]);
			$result = $relationMap->mapOne($result, $objects, 'relatedObject');
		}

		// adding alerts
		if ($options['select_alerts'] !== null && $options['select_alerts'] != API_OUTPUT_COUNT) {
			$relationMap = $this->createRelationMap($result, 'eventid', 'alertid', 'alerts');
			$alerts = API::Alert()->get([
				'output' => $options['select_alerts'],
				'selectMediatypes' => API_OUTPUT_EXTEND,
				'alertids' => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true,
				'sortfield' => 'clock',
				'sortorder' => ZBX_SORT_DOWN
			]);
			$result = $relationMap->mapMany($result, $alerts, 'alerts');
		}

		// adding acknowledges
		if ($options['select_acknowledges'] !== null) {
			if ($options['select_acknowledges'] != API_OUTPUT_COUNT) {
				// create the base query
				$sqlParts = API::getApiService()->createSelectQueryParts('acknowledges', 'a', [
					'output' => $this->outputExtend($options['select_acknowledges'],
						['acknowledgeid', 'eventid', 'clock', 'userid']
					),
					'filter' => ['eventid' => $eventIds]
				]);
				$sqlParts['order'][] = 'a.clock DESC';

				$acknowledges = DBFetchArrayAssoc(DBselect($this->createSelectQueryFromParts($sqlParts)), 'acknowledgeid');

				// if the user data is requested via extended output or specified fields, join the users table
				$userFields = ['alias', 'name', 'surname'];
				$requestUserData = [];
				foreach ($userFields as $userField) {
					if ($this->outputIsRequested($userField, $options['select_acknowledges'])) {
						$requestUserData[] = $userField;
					}
				}

				if ($requestUserData) {
					$users = API::User()->get([
						'output' => $requestUserData,
						'userids' => zbx_objectValues($acknowledges, 'userid'),
						'preservekeys' => true
					]);

					foreach ($acknowledges as &$acknowledge) {
						if (array_key_exists($acknowledge['userid'], $users)) {
							$acknowledge = array_merge($acknowledge, $users[$acknowledge['userid']]);
						}
					}
					unset($acknowledge);
				}

				$relationMap = $this->createRelationMap($acknowledges, 'eventid', 'acknowledgeid');
				$acknowledges = $this->unsetExtraFields($acknowledges, ['eventid', 'acknowledgeid', 'clock', 'userid'],
					$options['select_acknowledges']
				);
				$result = $relationMap->mapMany($result, $acknowledges, 'acknowledges');
			}
			else {
				$acknowledges = DBFetchArrayAssoc(DBselect(
					'SELECT COUNT(a.acknowledgeid) AS rowscount,a.eventid'.
						' FROM acknowledges a'.
						' WHERE '.dbConditionInt('a.eventid', $eventIds).
						' GROUP BY a.eventid'
				), 'eventid');
				foreach ($result as &$event) {
					if ((isset($acknowledges[$event['eventid']]))) {
						$event['acknowledges'] = $acknowledges[$event['eventid']]['rowscount'];
					}
					else {
						$event['acknowledges'] = 0;
					}
				}
				unset($event);
			}
		}

		// Adding event tags.
		if ($options['selectTags'] !== null && $options['selectTags'] != API_OUTPUT_COUNT) {
			if ($options['selectTags'] === API_OUTPUT_EXTEND) {
				$options['selectTags'] = ['tag', 'value'];
			}

			$tags_options = [
				'output' => $this->outputExtend($options['selectTags'], ['eventid']),
				'filter' => ['eventid' => $eventIds]
			];
			$tags = DBselect(DB::makeSql('event_tag', $tags_options));

			foreach ($result as &$event) {
				$event['tags'] = [];
			}
			unset($event);

			while ($tag = DBfetch($tags)) {
				$event = &$result[$tag['eventid']];

				unset($tag['eventtagid'], $tag['eventid']);
				$event['tags'][] = $tag;
			}
			unset($event);
		}

		return $result;
	}

	/**
	 * Checks if the given events exist, are accessible and can be acknowledged.
	 *
	 * @param array $eventids
	 *
	 * @throws APIException			If an event does not exist, is not accessible, is not a trigger event or event is
	 *								not in PROBLEM state.
	 */
	protected function checkCanBeAcknowledged(array $eventids) {
		$allowed_events = $this->get([
			'output' => ['eventid', 'value'],
			'eventids' => $eventids,
			'preservekeys' => true
		]);

		foreach ($eventids as $eventid) {
			if (array_key_exists($eventid, $allowed_events)) {
				// Prohibit acknowledging OK events.
				if ($allowed_events[$eventid]['value'] == TRIGGER_VALUE_FALSE) {
					self::exception(ZBX_API_ERROR_PERMISSIONS,
						_s('Cannot acknowledge problem: %1$s.', _('event is not in PROBLEM state'))
					);
				}
			}
			else {
				// Check if an event actually exists but maybe belongs to a different source or object.

				$event = API::getApiService()->select($this->tableName(), [
					'output' => ['eventid', 'source', 'object'],
					'eventids' => $eventid,
					'limit' => 1
				]);
				$event = reset($event);

				// If the event exists, check if we have permissions to access it.
				if ($event) {
					$event = $this->get([
						'output' => ['eventid'],
						'eventids' => $event['eventid'],
						'source' => $event['source'],
						'object' => $event['object'],
						'limit' => 1
					]);
				}

				if ($event) {
					// The event exists, is accessible but belongs to a different object or source.
					self::exception(ZBX_API_ERROR_PERMISSIONS, _('Only trigger events can be acknowledged.'));
				}
				else {
					// The event either doesn't exist or is not accessible.
					self::exception(ZBX_API_ERROR_PERMISSIONS,
						_('No permissions to referred object or it does not exist!')
					);
				}
			}
		}
	}

	/**
	 * Checks if the given events can be closed manually.
	 *
	 * @param array $eventids
	 *
	 * @throws APIException			If an event does not exist, is not accessible or trigger does not allow manual closing.
	 */
	protected function checkCanBeManuallyClosed(array $eventids) {
		$events_count = count($eventids);

		$events = $this->get([
			'output' => [],
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'editable' => true
		]);

		if ($events_count != count($events)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		$events = $this->get([
			'output' => [],
			'selectRelatedObject' => ['manual_close'],
			'eventids' => $eventids,
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
		]);

		if ($events_count != count($events)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS,
				_s('Cannot close problem: %1$s.', _('event is not in PROBLEM state'))
			);
		}

		foreach ($events as $event) {
			if ($event['relatedObject']['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_NOT_ALLOWED) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_s('Cannot close problem: %1$s.', _('trigger does not allow manual closing'))
				);
			}
		}
	}

	/**
	 * Function calculates what access user has to given host groups and events that are generated by triggers which
	 * belongs to hosts in given host groups.
	 *
	 * @param array $user_groups	A list of user groups.
	 * @param array $host_groups	A list of host groups.
	 *
	 * @return array	Contains two sub-arrays:
	 *					- First sub-array contains host groups (groupid is used as a key) in which events are available
	 *					  only in combination with specific tags (tag name-value pairs are used as values);
	 *					- Second sub-array contains only list of host groups (only groupid) that can be applied without
	 *					  tag filters.
	 */
	protected function calculateTagFilterRestriction(array $user_groups = [], array $host_groups = []) {
		// Get rights.
		$db_rights = DBselect(
			'SELECT r.groupid,r.id'.
			' FROM rights r'.
			' WHERE '.dbConditionInt('r.groupid', $user_groups).
				' AND '.dbConditionInt('r.id', $host_groups)
		);

		$rights = [];

		while ($db_right = DBfetch($db_rights)) {
			$rights[$db_right['groupid']][$db_right['id']] = true;
		}

		// Get tag filter.
		$db_tag_filters = DBselect(
			'SELECT tf.groupid,tf.tag,tf.value,tf.usrgrpid'.
			' FROM tag_filter tf'.
			' WHERE '.dbConditionInt('tf.usrgrpid', $user_groups)
		);

		$tag_filters = [];

		/**
		 * $host_groups_without_tags holds user groups and host groups on which access has been granted.
		 *
		 * Two type of values possible:
		 *  - hard access (value 1) - if access is calculated usinghost group  permissions and tag filters;
		 *  - soft access (value 0) - if access is granted from permissions tab only.
		 *
		 * Soft access can be removed if any other user group have more specific tag filter permissions set for
		 * particular host group. Hard access cannot be removed once granted.
		 *
		 * Types of access are used internally in this function only.
		 */
		$host_groups_without_tags = [];
		foreach ($rights as $usrgrpid => $groups) {
			foreach ($groups as $groupid => $value) {
				$host_groups_without_tags[$usrgrpid][$groupid] = 0;
			}
		}

		while ($db_tag_filter = DBfetch($db_tag_filters)) {
			/**
			 * If tag based permissions comes into force, delete soft access to particular host group if such has been
			 * granted before.
			 *
			 * If hard access to particular host group has been already granted, simply jump to the next tag.
			 */
			foreach ($host_groups_without_tags as $usrgrpid => $groups) {
				if (array_key_exists($db_tag_filter['groupid'], $groups)) {
					foreach ($groups as $groupid => $val) {
						if ($val == 0) {
							unset($host_groups_without_tags[$usrgrpid][$groupid]);
						}
						else {
							continue(2);
						}
					}
				}
			}

			/**
			 * If <tag name> and <tag value> are not specified, but tag filter for host group is created (otherwise
			 * wouldn't been such record in tag_filter table), simply grant hard access to particular host group.
			 */
			if ($db_tag_filter['tag'] === '' && $db_tag_filter['value'] === '') {
				if (in_array($db_tag_filter['groupid'], $host_groups)) {
					$host_groups_without_tags[$db_tag_filter['usrgrpid']][$db_tag_filter['groupid']] = 1;
				}

				/**
				 * Since un-removable access to whole host group has been granted, it is not necessary to store tags
				 * specified for particular host group anymore.
				 */
				if (array_key_exists($db_tag_filter['groupid'], $tag_filters)) {
					unset($tag_filters[$db_tag_filter['groupid']]);
				}
			}
			else {
				/**
				 * If at least one tag is set for particular user group, we must review all host group permissions that
				 * are added in particular user group. All host groups with soft access must be removed in particular
				 * user group.
				 */
				if (array_key_exists($db_tag_filter['usrgrpid'], $host_groups_without_tags)) {
					foreach ($host_groups_without_tags[$db_tag_filter['usrgrpid']] as $grpid => $val) {
						if ($val == 0) {
							unset($host_groups_without_tags[$db_tag_filter['usrgrpid']][$grpid]);
						}
					}
				}

				// Grant access to host group problems with particular Tag only.
				if (in_array($db_tag_filter['groupid'], $host_groups)) {
					$tag_filters[$db_tag_filter['groupid']][] = [
						'tag' => $db_tag_filter['tag'],
						'value' => $db_tag_filter['value']
					];
				}
			}
		}

		// Create SQL condition to select problems for particular host groups without specified tags.
		$full_access_groups = [];
		foreach ($host_groups_without_tags as $usrgrpid => $groups) {
			foreach ($groups as $groupid => $value) {
				$full_access_groups[$groupid] = true;
			}
		}

		return [
			$tag_filters,
			array_keys($full_access_groups)
		];
	}
}
