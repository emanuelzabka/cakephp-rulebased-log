<?php 
/* SVN FILE: $Id$ */
/* App schema generated on: 2011-04-28 00:04:03 : 1303960623*/
class RuleBasedLogSchema extends CakeSchema {
	var $name = 'RuleBasedLog';

	function before($event = array()) {
		return true;
	}

	function after($event = array()) {
	}

	public $logs = array(
		'id' => array('type' => 'integer', 'null' => false, 'default' => NULL, 'key' => 'primary'),
		'plugin' => array('type' => 'string', 'null' => false, 'length' => 250),
		'controller' => array('type' => 'string', 'null' => false, 'length' => 250),
		'model_class' => array('type' => 'string', 'null' => false, 'length' => 250),
		'user_login' => array('type' => 'string', 'null' => false, 'length' => 250),
		'message' => array('type' => 'text'),
		'ip' => array('type' => 'string', 'null' => false, 'length' => 16),
		'created' => array('type' => 'datetime', 'null' => false),
		'indexes' => array('PRIMARY' => array('column' => 'id', 'unique' => 1))
	);

}
?>
