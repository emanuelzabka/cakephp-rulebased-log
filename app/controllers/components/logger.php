<?php

/**
 * $Id$
 * Component for automatic logging in database depending on controller statuses
 * Exemplo de configurações:
 *   array(
 *	'states' => array(
 *		'controller' => 'name',
 *		'action' => 'action',
 *		'post' => array('isPost'),
 *		'status' => 'getProccessResult',
 *	),
 *	// Action to log
 *	'logAction' => 'logAction',
 *	// Rules for logging
 *	'rules' => array(
 *		'ADD' => array(
 *			'state' => array(
 *				'action' => 'add',
 *				'post' => true,
 *				'status' => 'success'
 *			),
 *			'message' => 'add'
 *		),
 *		'EDIT' => array(
 *			'state' => array(
 *				'action' => 'edit',
 *				'post' => true,
 *				'status' => 'success'
 *			),
 *			'message' => 'edit'
 *		),
 *		'REMOVE' => array(
 *			'state' => array(
 *				'action' => 'remove',
 *				'post' => true,
 *				'status' => 'success'
 *			),
 *			'message' => 'remove'
 *		),
 *	)
 * 
 * @uses Object
 * @package Core
 * @subpackage Component
 * @version $Rev$
 * @modified $Date$
 * @modifiedby $Author$
 */
class LoggerComponent extends Object {

	public $controller = null;
	public $settings = array();

	public function initialize($controller, $settings = array()) {
		$this->controller = $controller;
		$this->settings = $settings;
		// Adds/Override aditional settings to the component
		if (!empty($this->controller->logAdditionalSettings)) {
			$this->settings = array_merge_recursive($this->settings, $this->controller->logAdditionalSettings);
		}
	}

	public function startup($controller) {
		$this->run();
	}

	public function beforeRedirect($controller, $url, $state = null, $exit = true) {
		$this->run();
	}

	public function beforeRender() {
		$this->run();
	}

	/**
	 * Verifies if the state matches with the value
	 * There are 3 types of parameter:
	 * - A string with the name of any attribute of the controller class
	 * - An array with any method of the controller, so the returning value can be compared
	 * - An array with any boolean method of the controller. This method must start with '?'
	 * @param mixed $param Param
	 * @param mixed $value Value being compared
	 * @return boolean
	 */
	protected function matchStateParamValue($param, $value) {
		$result = false;
		if (is_array($param)) {
			$method = array_shift($param);
			if ($method[0] != '?') {
				$result = call_user_func_array(array($this->controller, $method), $param) == $value;
			} else {
				// Adiciona o valor para a callback
				array_unshift($param, $value);
				$result = call_user_func_array(array($this->controller, substr($method,1)), $param);
			}
		} else {
			$atr_val = null;
			// Atributo no controller
			$atrval = $this->controller->{$param};
			$result = $atrval == $value;
		}
		return $result;
	}

	/**
	 * Verifies if $stateData matches with controller data in the moment method is called.
	 * @return boolean
	 */
	protected function stateMatch($stateData) {
		foreach ($stateData as $state => $value) {
			$match = $this->matchStateParamValue($this->settings['states'][$state], $value);
			if (!$match) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns the matched rules
	 * @return array
	 */
	public function getMatchedRules() {
		$result = array();
		foreach ($this->settings['rules'] as $name => $rule) {
			$rule['name'] = $rule;
			if ($this->stateMatch($rule['state'])) {
				// Se não for exclusiva a regra adiciona a lista de regras, senão deve apenas adicionar a regra atual
				if (!isset($rule['exclusive']) || !$rule['exclusive']) {
					$result[] = $rule;
				} else {
					$result = array($rule);
					// É regra exclusiva então deve retornar apenas essa
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * Runs the log Action
	 */
	public function executeLog($rule) {
		if (!empty($this->settings['logAction'])) {
			$logAction = $this->settings['logAction'];
			$this->controller->$logAction($rule);
		}
	}

	/**
	 * Logs the matched rules
	 */
	public function run() {
		$matched_rules = $this->getMatchedRules();
		foreach ($matched_rules as $rule) {
			$this->executeLog($rule);
		}
	}

	/**
	 * Retorna os valores para a qual os wildcards das mensagens de log devem ser traduzidos 
	 * Returns the values to which wildcards must be translated
	 * Output example:
	 * array(
	 *   'name' => 'User.name'
	 * )
	 * @param array $datum Log line
	 * @return array
	 */
	public function getWildcards($datum) {
		/** FIXME: we should change module term for "plugin" **/
		$module = ucfirst(low($datum['Log']['module']));
		// Array contendo o nome descritivo dos controllers do módulo
		$objects = Configure::read('Log.objects.'.$module);
		$what = $datum['Log']['controller'];
		if (!empty($objects[$datum['Log']['controller']])) {
			$what = $objects[$datum['Log']['controller']];
		}
		$result = Array(
			'Module' => $datum['Log']['module'],
			'What' => $what
		);
		
		// Looks for controller values
		$module_wildcards = (array)Configure::read('Log.wildcards.'.$module);
		$controller_wildcards = (array)@$module_wildcards[$datum['Log']['controller']];
		foreach ($controller_wildcards as $wc => $wc_value) {
			if (empty($result[$wc])) {
				$result[$wc] = $wc_value;
			}
		}
		$values = (array)@$datum['Log']['data']['data'];
		// Looks for values for plugin
		if (empty($result['name'])) {
			$modelClass = $datum['Log']['model_class'];
			foreach ((array)@$module_wildcards['All'] as $wc_value) {
				if (Set::check($values, $modelClass.'.'.$wc_value)) {
					$result['name'] = ':'.$modelClass.'.'.$wc_value;
					break;
				}
			}
		}
		// Looks for values for project
		$wildcards_all = (array)Configure::read('Log.wildcards.All');
		if (empty($result['name'])) {
			$modelClass = $datum['Log']['model_class'];
			foreach ($wildcards_all as $wc_value) {
				if (Set::check($values, $modelClass.'.'.$wc_value)) {
					$result['name'] = ':'.$modelClass.'.'.$wc_value;	
					break;
				}
			}
		}
		return $result;
	}

	/**
	 * Translates log messages and replaces metatags/wildcards with log definitions
	 * Considers settings arrays for controller, plugin and project tags
	 * Settings array usage example:
	 * Configure::write('Log.wildcards.<Plugin name>', array(
	 * 	// Controller specific wildcards
	 * 	'User' => array(
	 * 		'name' => ':User.name (:User.login)'
	 * 	),
	 *  // Values for :name tag in log messages, excep User in User controller which is overriden for the up example
	 * 	'All' => array(
	 * 		'name', 'description'
	 * 	));
	 * Tags for the whole project can be set in Log.wildcards.All param
	 * Configure::write('Log.wildcards.All', array('name', 'description'));
	 * @param mixed $data Log messages
	 * @return array
	 */
	public function parseMessages($data) {
		foreach ($data as &$datum) {
			$log = $datum['Log']['message'];
			$log_data = $datum['Log']['data'] = json_decode($datum['Log']['data'],true);
			$wildcards = $this->getWildcards($datum);
			$message = Configure::read('Log.messages.'.$log);
			if (!empty($message)) {
				$message = String::insert($message,$wildcards);
			} else {
				$message = $log;
			}
			$datum['Log']['message'] = String::insert($message, Set::flatten((array)@$log_data['data']));
		}
		return $data;
	}

}

?>
