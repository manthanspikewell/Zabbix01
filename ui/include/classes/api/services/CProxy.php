<?php
/*
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


/**
 * Class containing methods for operations with proxies.
 */
class CProxy extends CApiService {

	public const ACCESS_RULES = [
		'get' => ['min_user_type' => USER_TYPE_ZABBIX_USER],
		'create' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'update' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'delete' => ['min_user_type' => USER_TYPE_SUPER_ADMIN]
	];

	protected $tableName = 'proxy';
	protected $tableAlias = 'p';
	protected $sortColumns = ['proxyid', 'name', 'operating_mode'];

	public const OUTPUT_FIELDS = ['proxyid', 'name', 'proxy_groupid', 'local_address', 'local_port', 'operating_mode',
		'allowed_addresses', 'address', 'port', 'description', 'tls_connect', 'tls_accept', 'tls_issuer', 'tls_subject',
		'custom_timeouts', 'timeout_zabbix_agent', 'timeout_simple_check', 'timeout_snmp_agent',
		'timeout_external_check', 'timeout_db_monitor', 'timeout_http_agent', 'timeout_ssh_agent',
		'timeout_telnet_agent', 'timeout_script', 'timeout_browser', 'lastaccess', 'version', 'compatibility', 'state'
	];

	/**
	 * @param array $options
	 *
	 * @throws APIException
	 *
	 * @return array|string
	 */
	public function get(array $options = []) {
		$output_fields = self::OUTPUT_FIELDS;

		/*
		 * For internal calls, it is possible to get the write-only fields if they were specified in output.
		 * Specify write-only fields in output only if they will not appear in debug mode.
		 */
		if (APP::getMode() !== APP::EXEC_MODE_API) {
			$output_fields[] = 'tls_psk_identity';
			$output_fields[] = 'tls_psk';
		}

		$api_input_rules = ['type' => API_OBJECT, 'fields' => [
			'proxyids' =>				['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'proxy_groupids' =>			['type' => API_IDS, 'flags' => API_ALLOW_NULL | API_NORMALIZE, 'default' => null],
			'filter' =>					['type' => API_FILTER, 'flags' => API_ALLOW_NULL, 'default' => null, 'fields' => ['proxyid', 'name', 'proxy_groupid', 'local_address', 'local_port', 'operating_mode', 'allowed_addresses', 'address', 'port', 'tls_connect', 'tls_accept', 'tls_issuer', 'tls_subject', 'custom_timeouts', 'timeout_zabbix_agent', 'timeout_simple_check', 'timeout_snmp_agent', 'timeout_external_check', 'timeout_db_monitor', 'timeout_http_agent', 'timeout_ssh_agent', 'timeout_telnet_agent', 'timeout_script', 'timeout_browser', 'lastaccess', 'version', 'compatibility', 'state']],
			'search' =>					['type' => API_FILTER, 'flags' => API_ALLOW_NULL, 'default' => null, 'fields' => ['name', 'local_address', 'local_port', 'allowed_addresses', 'address', 'port', 'description', 'timeout_zabbix_agent', 'timeout_simple_check', 'timeout_snmp_agent', 'timeout_external_check', 'timeout_db_monitor', 'timeout_http_agent', 'timeout_ssh_agent', 'timeout_telnet_agent', 'timeout_script', 'timeout_browser']],
			'searchByAny' =>			['type' => API_BOOLEAN, 'default' => false],
			'startSearch' =>			['type' => API_FLAG, 'default' => false],
			'excludeSearch' =>			['type' => API_FLAG, 'default' => false],
			'searchWildcardsEnabled' =>	['type' => API_BOOLEAN, 'default' => false],
			// output
			'output' =>					['type' => API_OUTPUT, 'in' => implode(',', $output_fields), 'default' => API_OUTPUT_EXTEND],
			'countOutput' =>			['type' => API_FLAG, 'default' => false],
			'selectAssignedHosts' =>	['type' => API_OUTPUT, 'flags' => API_ALLOW_NULL | API_ALLOW_COUNT, 'in' => implode(',', array_diff(CHost::OUTPUT_FIELDS, ['proxyid', 'proxy_groupid', 'assigned_proxyid'])), 'default' => null],
			'selectHosts' =>			['type' => API_OUTPUT, 'flags' => API_ALLOW_NULL | API_ALLOW_COUNT, 'in' => implode(',', array_diff(CHost::OUTPUT_FIELDS, ['proxyid', 'proxy_groupid', 'assigned_proxyid'])), 'default' => null],
			'selectProxyGroup' =>		['type' => API_OUTPUT, 'flags' => API_ALLOW_NULL, 'in' => implode(',', array_diff(CProxyGroup::OUTPUT_FIELDS, ['proxy_groupid'])), 'default' => null],
			// sort and limit
			'sortfield' =>				['type' => API_STRINGS_UTF8, 'flags' => API_NORMALIZE, 'in' => implode(',', $this->sortColumns), 'uniq' => true, 'default' => []],
			'sortorder' =>				['type' => API_SORTORDER, 'default' => []],
			'limit' =>					['type' => API_INT32, 'flags' => API_ALLOW_NULL, 'in' => '1:'.ZBX_MAX_INT32, 'default' => null],
			// flags
			'editable' =>				['type' => API_BOOLEAN, 'default' => false],
			'preservekeys' =>			['type' => API_BOOLEAN, 'default' => false],
			'nopermissions' =>			['type' => API_BOOLEAN, 'default' => false]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $options, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		// editable + PERMISSION CHECK
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			$permission = $options['editable'] ? PERM_READ_WRITE : PERM_READ;

			if ($permission == PERM_READ_WRITE) {
				return $options['countOutput'] ? '0' : [];
			}
		}

		if ($options['output'] === API_OUTPUT_EXTEND) {
			$options['output'] = $output_fields;
		}

		$resource = DBselect($this->createSelectQuery($this->tableName, $options), $options['limit']);

		$db_proxies = [];

		while ($row = DBfetch($resource)) {
			if ($options['countOutput']) {
				return $row['rowscount'];
			}

			$db_proxies[$row['proxyid']] = $row;
		}

		if ($db_proxies) {
			$db_proxies = $this->addRelatedObjects($options, $db_proxies);
			$db_proxies = $this->unsetExtraFields($db_proxies, ['proxyid', 'proxy_groupid'], $options['output']);

			if (!$options['preservekeys']) {
				$db_proxies = array_values($db_proxies);
			}
		}

		return $db_proxies;
	}

	protected function applyQueryFilterOptions($table_name, $table_alias, array $options, array $sql_parts): array {
		$sql_parts = parent::applyQueryFilterOptions($table_name, $table_alias, $options, $sql_parts);

		// proxy_groupids
		if ($options['proxy_groupids'] !== null) {
			$sql_parts['where'][] = dbConditionId('p.proxy_groupid', $options['proxy_groupids']);
		}

		if ($options['filter'] !== null) {
			$rt_filter = [];

			foreach (['lastaccess', 'version', 'compatibility', 'state'] as $field) {
				if (array_key_exists($field, $options['filter']) && $options['filter'][$field] !== null) {
					$rt_filter[$field] = $options['filter'][$field];
				}
			}

			if ($rt_filter) {
				$this->dbFilter('proxy_rtdata pr', ['filter' => $rt_filter] + $options, $sql_parts);

				$sql_parts['left_join']['proxy_rtdata'] = [
					'alias' => 'pr',
					'table' => 'proxy_rtdata',
					'using' => 'proxyid'
				];
				$sql_parts['left_table'] = ['alias' => $this->tableAlias, 'table' => $this->tableName];
			}
		}

		return $sql_parts;
	}

	protected function applyQueryOutputOptions($table_name, $table_alias, array $options, array $sql_parts): array {
		$sql_parts = parent::applyQueryOutputOptions($table_name, $table_alias, $options, $sql_parts);

		if (!$options['countOutput']) {
			if ($options['selectProxyGroup'] !== null) {
				$sql_parts = $this->addQuerySelect($this->fieldId('proxy_groupid'), $sql_parts);
			}

			$proxy_rtdata = false;

			foreach (['lastaccess', 'version', 'compatibility', 'state'] as $field) {
				if ($this->outputIsRequested($field, $options['output'])) {
					$sql_parts = $this->addQuerySelect('pr.'.$field, $sql_parts);
					$proxy_rtdata = true;
				}
			}

			if ($proxy_rtdata) {
				$sql_parts['left_join']['proxy_rtdata'] = [
					'alias' => 'pr',
					'table' => 'proxy_rtdata',
					'using' => 'proxyid'
				];
				$sql_parts['left_table'] = ['alias' => $this->tableAlias, 'table' => $this->tableName];
			}
		}

		return $sql_parts;
	}

	protected function addRelatedObjects(array $options, array $result): array {
		$result = parent::addRelatedObjects($options, $result);

		$this->addRelatedAssignedHosts($options, $result);
		$this->addRelatedHosts($options, $result);
		$this->addRelatedProxyGroup($options, $result);

		return $result;
	}

	private function addRelatedAssignedHosts(array $options, array &$result): void {
		if ($options['selectAssignedHosts'] === null) {
			return;
		}

		if ($options['selectAssignedHosts'] === API_OUTPUT_COUNT) {
			$output = ['hostid', 'assigned_proxyid'];
		}
		elseif ($options['selectAssignedHosts'] === API_OUTPUT_EXTEND) {
			$output = array_diff(CHost::OUTPUT_FIELDS, ['proxyid', 'proxy_groupid', 'assigned_proxyid']);
		}
		else {
			$output = $options['selectAssignedHosts'];
		}

		$db_hosts = API::Host()->get([
			'output' => $this->outputExtend($output, ['hostid', 'assigned_proxyid']),
			'filter' => ['assigned_proxyid' => array_keys($result)],
			'preservekeys' => true
		]);

		$relation_map = $this->createRelationMap($db_hosts, 'assigned_proxyid', 'hostid');
		$db_hosts = $this->unsetExtraFields($db_hosts, ['hostid', 'assigned_proxyid'], $output);
		$result = $relation_map->mapMany($result, $db_hosts, 'assignedHosts');

		if ($options['selectAssignedHosts'] === API_OUTPUT_COUNT) {
			foreach ($result as &$row) {
				$row['assignedHosts'] = (string) count($row['assignedHosts']);
			}
			unset($row);
		}
	}

	private function addRelatedHosts(array $options, array &$result): void {
		if ($options['selectHosts'] === null) {
			return;
		}

		if ($options['selectHosts'] === API_OUTPUT_COUNT) {
			$output = ['hostid', 'proxyid'];
		}
		elseif ($options['selectHosts'] === API_OUTPUT_EXTEND) {
			$output = array_diff(CHost::OUTPUT_FIELDS, ['proxyid', 'proxy_groupid', 'assigned_proxyid']);
		}
		else {
			$output = $options['selectHosts'];
		}

		$db_hosts = API::Host()->get([
			'output' => $this->outputExtend($output, ['hostid', 'proxyid']),
			'proxyids' => array_keys($result),
			'preservekeys' => true
		]);

		$relation_map = $this->createRelationMap($db_hosts, 'proxyid', 'hostid');
		$db_hosts = $this->unsetExtraFields($db_hosts, ['hostid', 'proxyid'], $output);
		$result = $relation_map->mapMany($result, $db_hosts, 'hosts');

		if ($options['selectHosts'] === API_OUTPUT_COUNT) {
			foreach ($result as &$row) {
				$row['hosts'] = (string) count($row['hosts']);
			}
			unset($row);
		}
	}

	private function addRelatedProxyGroup(array $options, array &$result): void {
		if ($options['selectProxyGroup'] === null) {
			return;
		}

		$relation_map = $this->createRelationMap($result, 'proxyid', 'proxy_groupid');

		$db_proxy_groups = API::ProxyGroup()->get([
			'output' => $options['selectProxyGroup'] === API_OUTPUT_EXTEND
				? array_diff(CProxyGroup::OUTPUT_FIELDS, ['proxy_groupid'])
				: $options['selectProxyGroup'],
			'proxy_groupids' => $relation_map->getRelatedIds(),
			'preservekeys' => true
		]);

		$result = $relation_map->mapOne($result, $db_proxy_groups, 'proxyGroup');
	}

	/**
	 * @param array $proxies
	 *
	 * @throws APIException
	 *
	 * @return array
	 */
	public function create(array $proxies): array {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS,
				_s('No permissions to call "%1$s.%2$s".', 'proxy', __FUNCTION__)
			);
		}

		self::validateCreate($proxies);

		$proxyids = DB::insert('proxy', $proxies);
		$proxy_rtdata = [];

		foreach ($proxies as $index => &$proxy) {
			$proxy['proxyid'] = $proxyids[$index];
			$proxy_rtdata[] = ['proxyid' => $proxyids[$index]];
		}
		unset($proxy);

		DB::insert('proxy_rtdata', $proxy_rtdata, false);
		self::updateHosts($proxies);

		self::addAuditLog(CAudit::ACTION_ADD, CAudit::RESOURCE_PROXY, $proxies);

		return ['proxyids' => $proxyids];
	}

	/**
	 * @param array $proxies
	 *
	 * @throws APIException
	 *
	 * @return array
	 */
	public function update(array $proxies): array {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS,
				_s('No permissions to call "%1$s.%2$s".', 'proxy', __FUNCTION__)
			);
		}

		$this->validateUpdate($proxies, $db_proxies);

		self::addFieldDefaultsByTypeFields($proxies, $db_proxies);

		$upd_proxies = [];

		foreach ($proxies as $proxy) {
			$upd_proxy = DB::getUpdatedValues('proxy', $proxy, $db_proxies[$proxy['proxyid']]);

			if ($upd_proxy) {
				$upd_proxies[] = [
					'values' => $upd_proxy,
					'where' => ['proxyid' => $proxy['proxyid']]
				];
			}
		}

		if ($upd_proxies) {
			DB::update('proxy', $upd_proxies);
		}

		self::updateHosts($proxies, $db_proxies);

		self::addAuditLog(CAudit::ACTION_UPDATE, CAudit::RESOURCE_PROXY, $proxies, $db_proxies);

		return ['proxyids' => array_column($proxies, 'proxyid')];
	}

	/**
	 * @param array      $proxies
	 * @param array|null $db_proxies
	 */
	private static function updateHosts(array $proxies, array $db_proxies = null): void {
		$upd_hosts = [];

		foreach ($proxies as $proxy) {
			if (!array_key_exists('hosts', $proxy)) {
				continue;
			}

			$db_hosts = $db_proxies !== null ? $db_proxies[$proxy['proxyid']]['hosts'] : [];

			foreach ($proxy['hosts'] as $host) {
				if (!array_key_exists($host['hostid'], $db_hosts)) {
					$upd_hosts[$host['hostid']] = [
						'values' => [
							'monitored_by' => ZBX_MONITORED_BY_PROXY,
							'proxyid' => $proxy['proxyid'],
							'proxy_groupid' => 0
						],
						'where' => ['hostid' => $host['hostid']]
					];
				}
				else {
					unset($db_hosts[$host['hostid']]);
				}
			}

			foreach ($db_hosts as $db_host) {
				if (!array_key_exists($db_host['hostid'], $upd_hosts)) {
					$upd_hosts[$db_host['hostid']] = [
						'values' => [
							'monitored_by' => ZBX_MONITORED_BY_SERVER,
							'proxyid' => 0
						],
						'where' => ['hostid' => $db_host['hostid']]
					];
				}
			}
		}

		if ($upd_hosts) {
			DB::update('hosts', array_values($upd_hosts));
		}
	}

	/**
	 * @param array $proxyids
	 *
	 * @throws APIException
	 *
	 * @return array
	 */
	public function delete(array $proxyids): array {
		if (self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			self::exception(ZBX_API_ERROR_PERMISSIONS,
				_s('No permissions to call "%1$s.%2$s".', 'proxy', __FUNCTION__)
			);
		}

		$this->validateDelete($proxyids, $db_proxies);

		DB::delete('host_proxy', ['proxyid' => $proxyids]);
		DB::delete('proxy_rtdata', ['proxyid' => $proxyids]);
		DB::delete('proxy', ['proxyid' => $proxyids]);

		self::addAuditLog(CAudit::ACTION_DELETE, CAudit::RESOURCE_PROXY, $db_proxies);

		return ['proxyids' => $proxyids];
	}

	/**
	 * @param array      $proxyids
	 * @param array|null $db_proxies
	 *
	 * @throws APIException
	 */
	private function validateDelete(array &$proxyids, ?array &$db_proxies): void {
		$api_input_rules = ['type' => API_IDS, 'flags' => API_NOT_EMPTY, 'uniq' => true];

		if (!CApiInputValidator::validate($api_input_rules, $proxyids, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$db_proxies = $this->get([
			'output' => ['proxyid', 'name'],
			'proxyids' => $proxyids,
			'editable' => true,
			'preservekeys' => true
		]);

		if (count($proxyids) != count($db_proxies)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		self::checkUsedInDiscovery($db_proxies);
		self::checkUsedInHosts($db_proxies);
		self::checkUsedInActions($db_proxies);
	}

	/**
	 * Check if proxy is used in network discovery rule.
	 *
	 * @param array  $proxies
	 * @param string $proxies[<proxyid>]['host']
	 *
	 * @throws APIException
	 */
	private static function checkUsedInDiscovery(array $proxies): void {
		$db_drules = DB::select('drules', [
			'output' => ['proxyid', 'name'],
			'filter' => ['proxyid' => array_keys($proxies)],
			'limit' => 1
		]);

		if ($db_drules) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Proxy "%1$s" is used by discovery rule "%2$s".',
				$proxies[$db_drules[0]['proxyid']]['name'], $db_drules[0]['name']
			));
		}
	}

	/**
	 * Check if proxy is used to monitor hosts.
	 *
	 * @param array  $proxies
	 * @param string $proxies[<proxyid>]['host']
	 *
	 * @throws APIException
	 */
	private static function checkUsedInHosts(array $proxies): void {
		$db_hosts = DB::select('hosts', [
			'output' => ['proxyid', 'name'],
			'filter' => ['proxyid' => array_keys($proxies)],
			'limit' => 1
		]);

		if ($db_hosts) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Host "%1$s" is monitored by proxy "%2$s".',
				$db_hosts[0]['name'], $proxies[$db_hosts[0]['proxyid']]['name']
			));
		}
	}

	/**
	 * Check if proxy is used in actions.
	 *
	 * @param array  $proxies
	 * @param string $proxies[<proxyid>]['host']
	 *
	 * @throws APIException
	 */
	private static function checkUsedInActions(array $proxies): void {
		$db_actions = DBfetchArray(DBselect(
			'SELECT a.name,c.value AS proxyid'.
			' FROM actions a,conditions c'.
			' WHERE a.actionid=c.actionid'.
				' AND c.conditiontype='.ZBX_CONDITION_TYPE_PROXY.
				' AND '.dbConditionString('c.value', array_keys($proxies)),
			1
		));

		if ($db_actions) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Proxy "%1$s" is used by action "%2$s".',
				$proxies[$db_actions[0]['proxyid']]['name'], $db_actions[0]['name']
			));
		}
	}

	/**
	 * @param array $proxies
	 *
	 * @throws APIException
	 */
	private static function validateCreate(array &$proxies): void {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'uniq' => [['name']], 'fields' => [
			'name' =>					['type' => API_H_NAME, 'flags' => API_REQUIRED, 'length' => DB::getFieldLength('proxy', 'name')],
			'proxy_groupid' =>			['type' => API_ID, 'default' => 0],
			'local_address' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'proxy_groupid', 'in' => 0], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'local_address')],
											['else' => true, 'type' => API_HOST_ADDRESS, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'local_address')]
			]],
			'local_port' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'proxy_groupid', 'in' => 0], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'local_port')],
											['else' => true, 'type' => API_PORT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'local_port')]
			]],
			'operating_mode' =>			['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [PROXY_OPERATING_MODE_ACTIVE, PROXY_OPERATING_MODE_PASSIVE])],
			'description' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'description')],
			'allowed_addresses' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_IP_RANGES, 'flags' => API_ALLOW_DNS, 'length' => DB::getFieldLength('proxy', 'allowed_addresses')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'allowed_addresses')]
			]],
			'address' => 				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'address')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_HOST_ADDRESS, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'address')]
			]],
			'port' =>					['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'port')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_PORT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'port')]
			]],
			'tls_connect' =>			['type' => API_MULTIPLE, 'default' => HOST_ENCRYPTION_NONE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_INT32, 'in' => DB::getDefault('proxy', 'tls_connect')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_INT32, 'in' => implode(',', [HOST_ENCRYPTION_NONE, HOST_ENCRYPTION_PSK, HOST_ENCRYPTION_CERTIFICATE])]
			]],
			'tls_accept' =>				['type' => API_MULTIPLE, 'default' => HOST_ENCRYPTION_NONE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_INT32, 'in' => HOST_ENCRYPTION_NONE.':'.(HOST_ENCRYPTION_NONE | HOST_ENCRYPTION_PSK | HOST_ENCRYPTION_CERTIFICATE)],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_INT32, 'in' => DB::getDefault('proxy', 'tls_accept')]
			]],
			'tls_issuer' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE; }, 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_issuer')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_issuer')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_CERTIFICATE], 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_issuer')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_issuer')]
											]]
			]],
			'tls_subject' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE; }, 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_subject')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_subject')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_CERTIFICATE], 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_subject')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_subject')]
											]]
			]],
			'tls_psk_identity' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_PSK; }, 'type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk_identity')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk_identity')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_PSK], 'type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk_identity')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk_identity')]
											]]
			]],
			'tls_psk' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_PSK; }, 'type' => API_PSK, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_PSK], 'type' => API_PSK, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk')]
											]]
			]],
			'custom_timeouts' =>		['type' => API_INT32, 'in' => implode(',', [ZBX_PROXY_CUSTOM_TIMEOUTS_DISABLED, ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED]), 'default' => DB::getDefault('proxy', 'custom_timeouts')],
			'timeout_zabbix_agent' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_zabbix_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_zabbix_agent')]
			]],
			'timeout_simple_check' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_simple_check')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_simple_check')]
			]],
			'timeout_snmp_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_snmp_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_snmp_agent')]
			]],
			'timeout_external_check' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_external_check')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_external_check')]
			]],
			'timeout_db_monitor' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_db_monitor')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_db_monitor')]
			]],
			'timeout_http_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_http_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_http_agent')]
			]],
			'timeout_ssh_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_ssh_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_ssh_agent')]
			]],
			'timeout_telnet_agent' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_telnet_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_telnet_agent')]
			]],
			'timeout_script' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_script')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_script')]
			]],
			'timeout_browser' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_REQUIRED | API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_browser')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_browser')]
			]],
			'hosts' =>					['type' => API_OBJECTS, 'uniq' => [['hostid']], 'fields' => [
				'hostid' =>					['type' => API_ID, 'flags' => API_REQUIRED]
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $proxies, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		self::checkDuplicates($proxies);
		self::checkProxyGroups($proxies);
		self::checkHosts($proxies);
	}

	/**
	 * @param array      $proxies
	 * @param array|null $db_proxies
	 *
	 * @throws APIException
	 */
	private static function checkDuplicates(array $proxies, array $db_proxies = null): void {
		$names = [];

		foreach ($proxies as $proxy) {
			if (!array_key_exists('name', $proxy)) {
				continue;
			}

			if ($db_proxies === null || $proxy['name'] !== $db_proxies[$proxy['proxyid']]['name']) {
				$names[] = $proxy['name'];
			}
		}

		if (!$names) {
			return;
		}

		$options = [
			'output' => ['name'],
			'filter' => [
				'name' => $names
			]
		];
		$duplicate = DBfetch(DBselect(DB::makeSql('proxy', $options), 1));

		if ($duplicate) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Proxy "%1$s" already exists.', $duplicate['name']));
		}
	}

	/**
	 * @param array      $proxies
	 * @param array|null $db_proxies
	 *
	 * @throws APIException
	 */
	private static function checkProxyGroups(array $proxies, array $db_proxies = null): void {
		$proxy_groupids = [];

		foreach ($proxies as $proxy) {
			if (!array_key_exists('proxy_groupid', $proxy) || $proxy['proxy_groupid'] == 0) {
				continue;
			}

			if ($db_proxies === null || $proxy['proxy_groupid'] !== $db_proxies[$proxy['proxyid']]['proxy_groupid']) {
				$proxy_groupids[$proxy['proxy_groupid']] = true;
			}
		}

		if (!$proxy_groupids) {
			return;
		}

		$db_count = API::ProxyGroup()->get([
			'countOutput' => true,
			'proxy_groupids' => array_keys($proxy_groupids)
		]);

		if ($db_count != count($proxy_groupids)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * @param array      $proxies
	 * @param array|null $db_proxies
	 *
	 * @throws APIException
	 */
	private static function checkHosts(array $proxies, array $db_proxies = null): void {
		$_hostids = [];

		foreach ($proxies as $proxy) {
			if (!array_key_exists('hosts', $proxy)) {
				continue;
			}

			$hostids = array_column($proxy['hosts'], null, 'hostid');
			$db_hostids = $db_proxies !== null
				? array_column($db_proxies[$proxy['proxyid']]['hosts'], null, 'hostid')
				: [];

			$_hostids += array_diff_key($hostids, $db_hostids);
		}

		if (!$_hostids) {
			return;
		}

		$db_hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'flags'],
			'hostids' => array_keys($_hostids),
			'editable' => true
		]);

		if (count($db_hosts) != count($_hostids)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
		}

		foreach ($db_hosts as $db_host) {
			if ($db_host['flags'] == ZBX_FLAG_DISCOVERY_CREATED) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Cannot update proxy for discovered host "%1$s".', $db_host['host'])
				);
			}
		}
	}

	/**
	 * @param array      $proxies
	 * @param array|null $db_proxies
	 *
	 * @throws APIException
	 */
	private function validateUpdate(array &$proxies, ?array &$db_proxies): void {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE | API_ALLOW_UNEXPECTED, 'uniq' => [['proxyid']], 'fields' => [
			'proxyid' =>			['type' => API_ID, 'flags' => API_REQUIRED],
			'proxy_groupid' =>		['type' => API_ID],
			'operating_mode' =>		['type' => API_INT32, 'in' => implode(',', [PROXY_OPERATING_MODE_ACTIVE, PROXY_OPERATING_MODE_PASSIVE])],
			'custom_timeouts' =>	['type' => API_INT32, 'in' => implode(',', [ZBX_PROXY_CUSTOM_TIMEOUTS_DISABLED, ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED])]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $proxies, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$count = $this->get([
			'countOutput' => true,
			'proxyids' => array_column($proxies, 'proxyid'),
			'editable' => true
		]);

		if ($count != count($proxies)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
		}

		$db_proxies = DB::select('proxy', [
			'output' => ['proxyid', 'name', 'proxy_groupid', 'local_address', 'local_port', 'operating_mode',
				'allowed_addresses', 'address', 'port', 'description', 'tls_connect', 'tls_accept', 'tls_issuer',
				'tls_subject', 'tls_psk_identity', 'tls_psk', 'custom_timeouts', 'timeout_zabbix_agent',
				'timeout_simple_check', 'timeout_snmp_agent', 'timeout_external_check', 'timeout_db_monitor',
				'timeout_http_agent', 'timeout_ssh_agent', 'timeout_telnet_agent', 'timeout_script', 'timeout_browser'
			],
			'proxyids' => array_column($proxies, 'proxyid'),
			'preservekeys' => true
		]);

		$proxies = $this->extendObjectsByKey($proxies, $db_proxies, 'proxyid', ['proxy_groupid', 'operating_mode',
			'custom_timeouts'
		]);

		self::checkCustomTimeouts($proxies, $db_proxies);

		self::addDbFieldsByProxyGroupId($proxies, $db_proxies);
		self::addFieldDefaultsByMode($proxies, $db_proxies);

		$proxies = $this->extendObjectsByKey($proxies, $db_proxies, 'proxyid', ['tls_connect', 'tls_accept']);

		self::addDbFieldsByCustomTimeouts($proxies, $db_proxies);

		$api_input_rules = ['type' => API_OBJECTS, 'uniq' => [['name']], 'fields' => [
			'proxyid' =>				['type' => API_ANY],
			'name' =>					['type' => API_H_NAME, 'length' => DB::getFieldLength('proxy', 'name')],
			'proxy_groupid' =>			['type' => API_ANY],
			'local_address' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'proxy_groupid', 'in' => 0], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'local_address')],
											['else' => true, 'type' => API_HOST_ADDRESS, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'local_address')]
			]],
			'local_port' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'proxy_groupid', 'in' => 0], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'local_port')],
											['else' => true, 'type' => API_PORT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'local_port')]
			]],
			'operating_mode' =>			['type' => API_ANY],
			'description' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'description')],
			'allowed_addresses' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_IP_RANGES, 'flags' => API_ALLOW_DNS, 'length' => DB::getFieldLength('proxy', 'allowed_addresses')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'allowed_addresses')]
			]],
			'address' => 				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'address')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_HOST_ADDRESS, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'address')]
			]],
			'port' =>					['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'port')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_PORT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'length' => DB::getFieldLength('proxy', 'port')]
			]],
			'tls_connect' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_INT32, 'in' => DB::getDefault('proxy', 'tls_connect')],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_INT32, 'in' => implode(',', [HOST_ENCRYPTION_NONE, HOST_ENCRYPTION_PSK, HOST_ENCRYPTION_CERTIFICATE])]
			]],
			'tls_accept' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_INT32, 'in' => HOST_ENCRYPTION_NONE.':'.(HOST_ENCRYPTION_NONE | HOST_ENCRYPTION_PSK | HOST_ENCRYPTION_CERTIFICATE)],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_INT32, 'in' => DB::getDefault('proxy', 'tls_accept')]
			]],
			'tls_issuer' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE; }, 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_issuer')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_issuer')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_CERTIFICATE], 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_issuer')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_issuer')]
											]]
			]],
			'tls_subject' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE; }, 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_subject')],
												['else' => true, 'type' => API_STRING_UTF8, DB::getDefault('proxy', 'tls_subject')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_CERTIFICATE], 'type' => API_STRING_UTF8, 'length' => DB::getFieldLength('proxy', 'tls_subject')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_subject')]
											]]
			]],
			'tls_psk_identity' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_PSK; }, 'type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk_identity')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk_identity')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_PSK], 'type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk_identity')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk_identity')]
											]]
			]],
			'tls_psk' =>				['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_ACTIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => static function ($data) { return $data['tls_accept'] & HOST_ENCRYPTION_PSK; }, 'type' => API_PSK, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk')]
											]],
											['if' => ['field' => 'operating_mode', 'in' => PROXY_OPERATING_MODE_PASSIVE], 'type' => API_MULTIPLE, 'rules' => [
												['if' => ['field' => 'tls_connect', 'in' => HOST_ENCRYPTION_PSK], 'type' => API_PSK, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('proxy', 'tls_psk')],
												['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'tls_psk')]
											]]
			]],
			'custom_timeouts' =>		['type' => API_ANY],
			'timeout_zabbix_agent' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_zabbix_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_zabbix_agent')]
			]],
			'timeout_simple_check' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_simple_check')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_simple_check')]
			]],
			'timeout_snmp_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_snmp_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_snmp_agent')]
			]],
			'timeout_external_check' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_external_check')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_external_check')]
			]],
			'timeout_db_monitor' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_db_monitor')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_db_monitor')]
			]],
			'timeout_http_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_http_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_http_agent')]
			]],
			'timeout_ssh_agent' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_ssh_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_ssh_agent')]
			]],
			'timeout_telnet_agent' =>	['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_telnet_agent')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_telnet_agent')]
			]],
			'timeout_script' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_script')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_script')]
			]],
			'timeout_browser' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'custom_timeouts', 'in' => ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED], 'type' => API_TIME_UNIT, 'flags' => API_NOT_EMPTY | API_ALLOW_USER_MACRO, 'in' => '1:600', 'length' => DB::getFieldLength('proxy', 'timeout_browser')],
											['else' => true, 'type' => API_STRING_UTF8, 'in' => DB::getDefault('proxy', 'timeout_browser')]
			]],
			'hosts' =>					['type' => API_OBJECTS, 'uniq' => [['hostid']], 'fields' => [
				'hostid' =>					['type' => API_ID, 'flags' => API_REQUIRED]
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $proxies, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		self::addAffectedObjects($proxies, $db_proxies);

		self::checkDuplicates($proxies, $db_proxies);
		self::checkProxyGroups($proxies, $db_proxies);
		self::checkHosts($proxies, $db_proxies);
	}

	/**
	 * @param array $proxies
	 * @param array $db_proxies
	 *
	 * @throws APIException
	 */
	private static function checkCustomTimeouts(array $proxies, array $db_proxies): void {
		$db_proxy_rtdata = DB::select('proxy_rtdata', [
			'output' => ['compatibility'],
			'proxyids' => array_column($proxies, 'proxyid'),
			'preservekeys' => true
		]);

		foreach ($proxies as $i => $proxy) {
			if ($proxy['custom_timeouts'] != $db_proxies[$proxy['proxyid']]['custom_timeouts']
					&& $proxy['custom_timeouts'] == ZBX_PROXY_CUSTOM_TIMEOUTS_ENABLED
					&& ($db_proxy_rtdata[$proxy['proxyid']]['compatibility'] == ZBX_PROXY_VERSION_OUTDATED
						|| $db_proxy_rtdata[$proxy['proxyid']]['compatibility'] == ZBX_PROXY_VERSION_UNSUPPORTED)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('Invalid parameter "%1$s": %2$s.', '/'.($i + 1).'/custom_timeouts',
						_('timeouts are disabled because the proxy and server versions do not match')
					)
				);
			}
		}
	}

	/**
	 * Add DB fields that became required as the result of the change of "proxy_groupid".
	 *
	 * @param array $proxies
	 * @param array $db_proxies
	 */
	private static function addDbFieldsByProxyGroupId(array &$proxies, array $db_proxies): void {
		foreach ($proxies as &$proxy) {
			if ($proxy['proxy_groupid'] !== $db_proxies[$proxy['proxyid']]['proxy_groupid']
					&& $proxy['proxy_groupid'] != 0) {
				$proxy += array_intersect_key($db_proxies[$proxy['proxyid']], array_flip(['local_address',
					'local_port'
				]));
			}
		}
		unset($proxy);
	}

	/**
	 * Add default values for fields that became unnecessary as the result of the change of operating_mode.
	 *
	 * @param array $proxies
	 * @param array $db_proxies
	 */
	private static function addFieldDefaultsByMode(array &$proxies, array $db_proxies): void {
		foreach ($proxies as &$proxy) {
			if ($proxy['operating_mode'] != $db_proxies[$proxy['proxyid']]['operating_mode']) {
				$proxy += $proxy['operating_mode'] == PROXY_OPERATING_MODE_ACTIVE
					? ['tls_connect' => DB::getDefault('proxy', 'tls_connect')]
					: ['tls_accept' => DB::getDefault('proxy', 'tls_accept')];
			}
		}
		unset($proxy);
	}

	/**
	 * Add DB fields that became required as the result of the change of "custom_timeouts".
	 *
	 * @param array $proxies
	 * @param array $db_proxies
	 */
	private static function addDbFieldsByCustomTimeouts(array &$proxies, array $db_proxies): void {
		foreach ($proxies as &$proxy) {
			if ($proxy['custom_timeouts'] !== $db_proxies[$proxy['proxyid']]['custom_timeouts']
					&& $proxy['custom_timeouts'] == ZBX_ITEM_CUSTOM_TIMEOUT_ENABLED) {
				$proxy += array_intersect_key($db_proxies[$proxy['proxyid']], array_flip(['timeout_zabbix_agent',
					'timeout_simple_check', 'timeout_snmp_agent', 'timeout_external_check', 'timeout_db_monitor',
					'timeout_http_agent', 'timeout_ssh_agent', 'timeout_telnet_agent', 'timeout_script',
					'timeout_browser'
				]));
			}
		}
		unset($proxy);
	}

	private static function addFieldDefaultsByTypeFields(array &$proxies, array $db_proxies): void {
		self::addFieldDefaultsByProxyGroupId($proxies, $db_proxies);
		self::addFieldDefaultsByOperatingMode($proxies, $db_proxies);
		self::addFieldDefaultsByTls($proxies, $db_proxies);
		self::addFieldDefaultsByCustomTimeouts($proxies, $db_proxies);
	}

	private static function addFieldDefaultsByProxyGroupId(array &$proxies, array $db_proxies): void {
		$db_defaults = DB::getDefaults('proxy');

		foreach ($proxies as &$proxy) {
			if ($proxy['proxy_groupid'] !== $db_proxies[$proxy['proxyid']]['proxy_groupid']
					&& $proxy['proxy_groupid'] == 0) {
				$proxy += array_intersect_key($db_defaults, array_flip(['local_address', 'local_port']));
			}
		}
		unset($proxy);
	}

	private static function addFieldDefaultsByOperatingMode(array &$proxies, array $db_proxies): void {
		$db_defaults = DB::getDefaults('proxy');

		foreach ($proxies as &$proxy) {
			if ($proxy['operating_mode'] != $db_proxies[$proxy['proxyid']]['operating_mode']) {
				$proxy += $proxy['operating_mode'] == PROXY_OPERATING_MODE_ACTIVE
					? array_intersect_key($db_defaults, array_flip(['address', 'port']))
					: array_intersect_key($db_defaults, array_flip(['allowed_addresses']));
			}
		}
		unset($proxy);
	}

	/**
	 * Add default values for fields that became unnecessary as the result of the change of operating_mode and
	 * TLS fields.
	 *
	 * @param array $proxies
	 * @param array $db_proxies
	 */
	private static function addFieldDefaultsByTls(array &$proxies, array $db_proxies): void {
		foreach ($proxies as &$proxy) {
			$db_proxy = $db_proxies[$proxy['proxyid']];

			if ($proxy['operating_mode'] != $db_proxy['operating_mode'] || $proxy['tls_connect'] != $db_proxy['tls_connect']
					|| $proxy['tls_accept'] != $db_proxy['tls_accept']) {
				if (($proxy['operating_mode'] == PROXY_OPERATING_MODE_ACTIVE && ($proxy['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE) == 0)
						|| ($proxy['operating_mode'] == PROXY_OPERATING_MODE_PASSIVE
							&& $proxy['tls_connect'] != HOST_ENCRYPTION_CERTIFICATE)) {
					$proxy += [
						'tls_issuer' => DB::getDefault('proxy', 'tls_issuer'),
						'tls_subject' => DB::getDefault('proxy', 'tls_subject')
					];
				}

				if (($proxy['operating_mode'] == PROXY_OPERATING_MODE_ACTIVE && ($proxy['tls_accept'] & HOST_ENCRYPTION_PSK) == 0)
						|| ($proxy['operating_mode'] == PROXY_OPERATING_MODE_PASSIVE && $proxy['tls_connect'] != HOST_ENCRYPTION_PSK)) {
					$proxy += DB::getUpdatedValues('proxy', [
						'tls_psk_identity' => DB::getDefault('proxy', 'tls_psk_identity'),
						'tls_psk' => DB::getDefault('proxy', 'tls_psk')
					], $db_proxy);
				}
			}
		}
		unset($proxy);
	}

	private static function addFieldDefaultsByCustomTimeouts(array &$proxies, array $db_proxies): void {
		$db_defaults = DB::getDefaults('proxy');

		foreach ($proxies as &$proxy) {
			if ($proxy['custom_timeouts'] != $db_proxies[$proxy['proxyid']]['custom_timeouts']
					&& $proxy['custom_timeouts'] == ZBX_PROXY_CUSTOM_TIMEOUTS_DISABLED) {
				$proxy += array_intersect_key($db_defaults, array_flip(['timeout_zabbix_agent', 'timeout_simple_check',
					'timeout_snmp_agent', 'timeout_external_check', 'timeout_db_monitor', 'timeout_http_agent',
					'timeout_ssh_agent', 'timeout_telnet_agent', 'timeout_script', 'timeout_browser'
				]));
			}
		}
		unset($proxy);
	}

	/**
	 * @param array $proxies
	 * @param array $db_proxies
	 */
	private static function addAffectedObjects(array $proxies, array &$db_proxies): void {
		$proxyids = ['hosts' => []];

		foreach ($proxies as $proxy) {
			if (array_key_exists('hosts', $proxy)) {
				$proxyids['hosts'][] = $proxy['proxyid'];
				$db_proxies[$proxy['proxyid']]['hosts'] = [];
			}
		}

		if ($proxyids['hosts']) {
			$options = [
				'output' => ['hostid', 'proxyid'],
				'filter' => ['proxyid' => $proxyids['hosts']]
			];
			$db_hosts = DBselect(DB::makeSql('hosts', $options));

			while ($db_host = DBfetch($db_hosts)) {
				$db_proxies[$db_host['proxyid']]['hosts'][$db_host['hostid']] = [
					'hostid' => $db_host['hostid']
				];
			}
		}
	}
}
