<?php

/**
 * $Id$
 * Componente para a realização automática de logs com base a estados no controller
 * Exemplo de configurações:
// Definição para os estados da regra
'states' => array(
	'module' => array('getModule'),
	'controller' => 'name',
	'action' => 'action',
	'post' => array('isPost'),
	'status' => 'getProccessResult',
),
// Ação de log
'logAction' => 'logAction',
// Regras para log
'rules' => array(
	'LOGIN' => array(
		'state' => array(
			'action' => 'login',
			'controller' => 'Users',
			'post' => true,
			'exclusive' => false,
			'status' => 'success'
		),
		'message' => 'login'
	),
	'ADD' => array(
		'state' => array(
			'action' => 'add',
			'post' => true,
			'status' => 'success'
		),
		'message' => 'add'
	),
	'EDIT' => array(
		'state' => array(
			'action' => 'edit',
			'post' => true,
			'status' => 'success'
		),
		'message' => 'edit'
	),
	'REMOVE' => array(
		'state' => array(
			'action' => 'remove',
			'post' => true,
			'status' => 'success'
		),
		'message' => 'remove'
	),
)
 * 
 * @uses Object
 * @package Core
 * @subpackage Component
 * @version $Rev$
 * @modified $Date$
 * @modifiedby $Author$
 */
class LoggerComponent extends Object {
	/** Controller do componente */
	public $controller = null;
	/** Configurações do componente */
	public $settings = array();

	public function initialize($controller, $settings = array()) {
		$this->controller = $controller;
		$this->settings = $settings;
		// Adiciona settings adicionais de possíveis controllers filhos
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
	 * Verifica se o parâmetro de estado bate com o valor.
	 * O parâmetro pode ser de dois 3 tipos.
	 * - O parâmetro é o nome do atributo do controller que deve ser igual ao valor
	 * - O parâmetro é um array contendo um método a ser chamado no controller para pegar o valor a ser comparado
	 * - O parâmetro é um array onde o nome do método começa com '?', para este método será passado o valor e ele retornará se é verdadeiro ou não
	 * @param mixed $param Parâmetro
	 * @param mixed $value Valor sendo comparado
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
			eval('$atrval = $this->controller->'.$param.';');
			$result = $atrval == $value;
		}
		return $result;
	}

	/**
	 * Verifica se o state casa com o momento atual
	 * @return boolean
	 */
	protected function stateMatch($stateData) {
		$match = true;
		foreach ($stateData as $state => $value) {
			$match = $this->matchStateParamValue($this->settings['states'][$state], $value);
			if (!$match) {
				break;
			}
		}
		return $match;
	}

	/**
	 * Retorna as regras que casam com o estado atual
	 * @return array
	 */
	public function getMatchedRules() {
		$result = array();
		foreach ($this->settings['rules'] as $name => $rule) {
			$rule['name'] = $rule;
			if ($this->stateMatch($rule['state'])) {
				// Se não for exclusiva a regra adiciona a lista de regras, senão deve apenas adicionar a regra atual
				if (!isset($rule['exclusive']) || $rule['exclusive']) {
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
	 * Executa o log para a regra
	 */
	public function executeLog($rule) {
		if (!empty($this->settings['logAction'])) {
			$logAction = $this->settings['logAction'];
			$this->controller->$logAction($rule);
		}
	}

	/**
	 * Executa o processo de log
	 */
	public function run() {
		$matched_rules = $this->getMatchedRules();
		foreach ($matched_rules as $rule) {
			// Executa o log
			$this->executeLog($rule);
		}
	}

	/**
	 * Retorna os valores para a qual os wildcards das mensagens de log devem ser traduzidos 
	 * Exemplo de saída:
	 * array(
	 *   'name' => 'Userhost.description'
	 * )
	 * @param mixed $datum Linha de log
	 * @return array
	 */
	public function getWildcards($datum) {
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
		
		// Busca regras para o controller
		$module_wildcards = (array)Configure::read('Log.wildcards.'.$module);
		$controller_wildcards = (array)@$module_wildcards[$datum['Log']['controller']];
		foreach ($controller_wildcards as $wc => $wc_value) {
			if (empty($result[$wc])) {
				$result[$wc] = $wc_value;
			}
		}
		$values = (array)@$datum['Log']['data']['data'];
		// Busca valores para All do Módulo
		if (empty($result['name'])) {
			$modelClass = $datum['Log']['model_class'];
			foreach ((array)@$module_wildcards['All'] as $wc_value) {
				if (Set::check($values, $modelClass.'.'.$wc_value)) {
					$result['name'] = ':'.$modelClass.'.'.$wc_value;
					break;
				}
			}
		}
		// Busca valores para All geral
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
	 * Traduz as mensagens de log e realiza as substituições das metatags/wildcards nas definições de log 
	 * Leva em consideração arrays de configuração para tags a nível de controller, módulo e geral
	 * Exemplo de arrays de configuração:
	 * Configure::write('Log.wildcards.Core', array(
	 * 	// Para controllers específicos
	 * 	'Userhost' => array(
	 * 		'name' => ':Userhost.description (:Userhost.address)'
	 * 	),
	 * 	// Valores para tag :name nas mensagens de log, considerados conforme sua ordem. Ex: verifica Userhost.name, depois Userhost.description, ...
	 * 	'All' => array(
	 * 		'name', 'description'
	 * 	));
	 * // O mesmo que o 'All' do módulo porém para todo o sistema, estes são considerados por último na decisão do valor para tag :name
	 * Configure::write('Log.wildcards.All', array('name', 'description'));
	 * @param mixed $data Linhas de log
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
