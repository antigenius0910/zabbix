<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerScriptUpdate extends CController {

	protected function checkInput() {
		$fields = [
			'scriptid' =>				'fatal|required|db scripts.scriptid',
			'name' =>					'db scripts.name',
			'type' =>					'db scripts.type|in '.ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT.','.ZBX_SCRIPT_TYPE_IPMI.','.ZBX_SCRIPT_TYPE_WEBHOOK,
			'execute_on' =>				'db scripts.execute_on|in '.ZBX_SCRIPT_EXECUTE_ON_AGENT.','.ZBX_SCRIPT_EXECUTE_ON_SERVER.','.ZBX_SCRIPT_EXECUTE_ON_PROXY,
			'command' =>				'db scripts.command|flags '.P_CRLF,
			'commandipmi' =>			'db scripts.command|flags '.P_CRLF,
			'parameters' =>				'array',
			'script' => 				'db scripts.command|flags '.P_CRLF,
			'timeout' => 				'db scripts.timeout|time_unit '.implode(':', [1, 60]),
			'description' =>			'db scripts.description',
			'host_access' =>			'db scripts.host_access|in '.PERM_READ.','.PERM_READ_WRITE,
			'groupid' =>				'db scripts.groupid',
			'usrgrpid' =>				'db scripts.usrgrpid',
			'hgstype' =>				'in 0,1',
			'confirmation' =>			'db scripts.confirmation|not_empty',
			'enable_confirmation' =>	'in 1',
			'form_refresh' =>			'int32'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			switch ($this->GetValidationError()) {
				case self::VALIDATION_ERROR:
					$response = new CControllerResponseRedirect('zabbix.php?action=script.edit');
					$response->setFormData($this->getInputAll());
					CMessageHelper::setErrorTitle(_('Cannot update script'));
					$this->setResponse($response);
					break;
				case self::VALIDATION_FATAL_ERROR:
					$this->setResponse(new CControllerResponseFatal());
					break;
			}
		}

		return $ret;
	}

	protected function checkPermissions() {
		if (!$this->checkAccess(CRoleHelper::UI_ADMINISTRATION_SCRIPTS)) {
			return false;
		}

		return (bool) API::Script()->get([
			'output' => [],
			'scriptids' => $this->getInput('scriptid'),
			'editable' => true
		]);
	}

	protected function doAction() {
		$script = [];

		$this->getInputs($script, ['scriptid', 'command', 'description', 'usrgrpid', 'groupid', 'host_access']);
		$script['name'] = trimPath($this->getInput('name', ''));
		$script['type'] = $this->getInput('type', ZBX_SCRIPT_TYPE_WEBHOOK);
		$script['execute_on'] = $this->getInput('execute_on', ZBX_SCRIPT_EXECUTE_ON_PROXY);
		$script['confirmation'] = $this->getInput('confirmation', '');

		if ($script['type'] != ZBX_SCRIPT_TYPE_CUSTOM_SCRIPT) {
			$script['execute_on'] = ZBX_SCRIPT_EXECUTE_ON_PROXY;
		}

		if ($script['type'] == ZBX_SCRIPT_TYPE_IPMI) {
			$script['command'] = $this->getInput('commandipmi', '');
		}
		elseif ($script['type'] == ZBX_SCRIPT_TYPE_WEBHOOK) {
			$script['command'] = $this->getInput('script', '');
			$script['timeout'] = $this->getInput('timeout', DB::getDefault('scripts', 'timeout'));
			$script['parameters'] = [];
			$parameters = $this->getInput('parameters', []);

			if (array_key_exists('name', $parameters) && array_key_exists('value', $parameters)) {
				$script['parameters'] = array_map(function ($name, $value) {
						return compact('name', 'value');
					},
					$parameters['name'],
					$parameters['value']
				);
			}
		}

		if ($this->getInput('hgstype', 1) == 0) {
			$script['groupid'] = 0;
		}

		$result = (bool) API::Script()->update($script);

		if ($result) {
			$response = new CControllerResponseRedirect((new CUrl('zabbix.php'))
				->setArgument('action', 'script.list')
				->setArgument('page', CPagerHelper::loadPage('script.list', null))
			);
			$response->setFormData(['uncheck' => '1']);
			CMessageHelper::setSuccessTitle(_('Script updated'));
		}
		else {
			$response = new CControllerResponseRedirect((new CUrl('zabbix.php'))
				->setArgument('action', 'script.edit')
				->setArgument('scriptid', $this->getInput('scriptid'))
			);
			$response->setFormData($this->getInputAll());
			CMessageHelper::setErrorTitle(_('Cannot update script'));
		}
		$this->setResponse($response);
	}
}
