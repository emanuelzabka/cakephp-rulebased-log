<?php

/**
 * Component for automatic logging in database depending on controller statuses
 * Setting example:
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
 */
class LoggerComponent extends Object {

	public $controller = null;
	public $settings = array();
	public $builtinStates = Array(
		'plugin' => 'plugin',
		'controller' => 'name',
		'action' => 'action',
		'post' => array('isPost'),
		'status' => 'actionStatus'
	);
	public $defaultLogAction = 'logAction';
	public $builtinRules = Array(
		'add' => array(
			'state' => array('action' => 'add', 'post' => true, 'status' => 'success'),
			'message' => 'added new :what'
		),
		'edit' => array(
			'state' => array('action' => 'edit', 'post' => true, 'status' => 'success'),
			'message' => ':what edited'
		),
		'delete' => array(
			'state' => array('action' => 'delete', 'post' => false),
			'message' => ':what removed'
		)
	);
	public $defaultSessionLoginPath = 'User.login';

	public function initialize($controller) {
		$this->controller = $controller;
		$defaultSettings = Array(
			'states' => $this->builtinStates,
			'logAction' => $this->defaultLogAction,
			'rules' => $this->builtinRules,
			'login_path' => $this->defaultSessionLoginPath
		);
		$this->settings = array_merge($defaultSettings, (array)Configure::read('Log.settings'));
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
	 * There are 2 types of parameter:
	 * - A string with the name of any attribute of the controller class
	 * - An array with any method of the controller, so the returning value can be compared
	 * @param mixed $param Param
	 * @param mixed $value Value being compared
	 * @return boolean
	 */
	protected function matchStateParamValue($param, $value) {
		if (is_array($param)) {
			$method = array_shift($param);
			if (method_exists($this->controller, $method)) {
				$result = call_user_func_array(array($this->controller, $method), $param);
			} else {
				$result = call_user_func_array(array($this, $method), $param);
			}
			$result = ($result == $value);
		} else {
			// It's an attribute
			$atrval = $this->controller->{$param};
			$result = ($atrval == $value);
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
		foreach ((array)@$this->settings['rules'] as $name => $rule) {
			$rule['name'] = $name;
			if (!array_key_exists('matched', $rule)) {
				$this->settings['rules'][$name]['matched'] = false;
				$rule['matched'] = false;
			}
			if (!$rule['matched'] && $this->stateMatch($rule['state'])) {
				$this->settings['rules'][$name]['matched'] = true;
				// If it is not exclusive, all the rules matching will be returned. Otherwise, only the first matching rule will be returned.
				if (!isset($rule['exclusive']) || !$rule['exclusive']) {
					$result[] = $rule;
				} else {
					$result = array($rule);
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
			if (method_exists($this->controller, $logAction))
				$this->controller->$logAction($rule);
			else
				$this->$logAction($rule);
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

	public function formatMessage($msg) {
		$objects = Configure::read('Log.objects.All');
		if ($this->controller->plugin)
			$objects = array_merge($objects, Configure::read('Log.objects.'.$this->controller->plugin));

		$what = $this->controller->name;
		if (!empty($objects[$what]))
			$what = $objects[$what];

		return String::insert($msg, array('what' => $what));
	}

	public function logAction($rule) {
		$Log = ClassRegistry::init('Rblog.Log');
		$Log->create();
		$Log->save(Array(
			'plugin' => (is_null($this->controller->plugin) ? 'App' : $this->controller->plugin),
			'controller' => $this->controller->name,
			'model_class' => $this->controller->modelClass,
			'user_login' => $this->controller->Session->read($this->settings['login_path']),
			'message' => $this->formatMessage($rule['message']),
			'ip' => 'localhost'
		));
	}

	public function isPost($param = null) {
		return in_array($_SERVER['REQUEST_METHOD'], array('POST','PUT'));
	}

	public function __call($method, $args) {
		return null;
	}

}
