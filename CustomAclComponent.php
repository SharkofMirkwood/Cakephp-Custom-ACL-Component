<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright       Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @author          Connor Leigh-Smith <connor@leigh-smith.me>
 * @link            http://leigh-smith.me
 * @since           CakePHP(tm) v 0.10.0.1076
 * @license         None
 */

App::uses('Component', 'Controller');
class CustomAclComponent extends Component {

    public $components = array('Auth');

    public function initialize(&$controller, $settings = array()) {
        $this->_controller = $controller;
    }

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->AroAco = ClassRegistry::init(array('class' => 'CustomAroAco', 'alias' => 'AroAco', 'table' => 'aros_acos'));
		$this->Aro = ClassRegistry::init(array('class' => 'CustomAro', 'alias' => 'Aro', 'table' => 'aros'));
		$this->Aco = ClassRegistry::init(array('class' => 'CustomAco', 'alias' => 'Aco', 'table' => 'acos'));
	}

	/**
	 * Recursive method called by other methods to check if an ARO
	 * is allowed access to an ACO (or, failing that, parent ACOs). 
	 *
	 * @param int $aro_id ARO ID of the ARO to check
	 * @param int $aco_id ACO ID of the ACO to check
	 * @return int -1 for denied, 1 for granted, 0 for nothing found.
	 */
	private function checkAroPermission($aro_id, $aco_id) {
		//Check the ACO to see if user is allowed to access it.
		$result = $this->AroAco->find('first', array(
			'conditions' => array('aco_id' => $aco_id, 'aro_id' => $aro_id)));
		if(count($result))
            return $result['AroAco']['permission'];

		//Check any parent ACOs to see if user is allowed to access it.
		$this->Aco->id = $aco_id;
		$parent_aco = $this->Aco->field('parent_aco');
		if($parent_aco != null) {
			return $this->checkAroPermission($aro_id, $parent_aco);
		}

		//No record found for this ARO/ACO.
		return 0;
	}

	/**
	 * Uses checkAroPermission to make a check based on user ID instead of ARO ID. 
	 *
	 * @param int $user_id User ID of the user to check
	 * @param int $aco_id ACO ID of the ACO to check
	 * @return int -1 for denied, 1 for granted, 0 for nothing found.
 	 * @throws CakeException when ARO is not found.
	 */
	private function checkUserPermission($user_id, $aco_id) {
		$aro = $this->Aro->find('first', array('conditions' => array(
			'type' 			=> 'user',
			'foriegn_key' 	=> $user_id )));
		if (!$this->Aro->exists($aro['Aro']['id'])) {
			throw new CakeException(__d('cake_dev', 'ARO for user %s cannot be found!', $user_id));
		}
		if($aro) return $this->checkAroPermission($aro['Aro']['id'], $aco_id);
		return 0;
	}

	/**
	 * Uses checkAroPermission to make a check based on role ID instead of ARO ID. 
	 *
	 * @param int $role_id Role ID of the role to check
	 * @param int $aco_id ACO ID of the ACO to check
	 * @return int -1 for denied, 1 for granted, 0 for nothing found.
 	 * @throws CakeException when ARO is not found.
	 */
	private function checkRolePermission($role_id, $aco_id) {
		$aro = $this->Aro->find('first', array('conditions' => array(
			'type' 			=> 'role',
			'foriegn_key' 	=> $role_id )));
        if (count($aro))
            return $this->checkAroPermission($aro['Aro']['id'], $aco_id);
		return 0;
	}

	/**
	 * Checks for permission using user ID given. If no record exists for user,
	 * also checks all user's roles to see if something exists there and returns
	 * that. Denial in any role will take priority over any roles that grant permission. 
	 *
	 * @param int $user_id User ID of the user to check
	 * @param int $aco_id ACO ID of the ACO to check
	 * @return int -1 for denied, 1 for granted, 0 for nothing found.
	 */
    private function checkPermission($user_id, $aco_id) {
    	//Assume aco and aro are integers for the id fields of each one.
    	//First check if any rules exist for the user:
    	if ($this->checkUserPermission($user_id, $aco_id) != 0)
    		return $this->checkUserPermission($user_id, $aco_id);

    	//Otherwise check all the user's roles (permission of -1 always has priority):
		$this->UserRole = ClassRegistry::init(array('class' => 'UserRole', 'alias' => 'UserRole'));
		$roles = $this->UserRole->find('all', array('conditions' => array('user_id' => $user_id)));

		$return_permission = 0;
		foreach ($roles as $role) {
			$permission = $this->checkRolePermission($role['UserRole']['role_id'], $aco_id);
            if($permission == -1)
                return -1;
            else
                $return_permission = $permission;
		}
		return $return_permission;
    }

    /**
     * Searches for an ACO ID based on a controller/action/parameter array.
     * If none is found, removes action/parameter and tries the parent 
     * (just controller).
     *
     * @param array $url_params Array with in typical CakePHP URL form.
     * @return int ID number of the ACO for the array provided, ot false 
     * if none found.
     */
    private function findAco($url_params) {
        $aco = $url_params;
        $this_aco = $this->Aco->find('first', array('conditions' => $aco));
        if (!count($this_aco)) {            //No ACO found.
            if($aco['action'] != null) {
                $aco['action'] = null;
                $aco['param'] = null;
                return $this->findAco($aco);
            } else {
                return false;
            }
        } else {
            return $this_aco['Aco']['id'];
        }
    }


	/**
	 * Performs an ACL check. If params are empty, takes current values (signed in user
	 * for ARO and/or current page for ACO).
	 *
	 * @param int $user User ID of the user to check
	 * @param int $aco ACO ID of the ACO to check
	 * @param boolean $role Role Whether or not the ARO is a role; if not, it's a user.
	 * @param boolean $use_param Parameter Whether or not to use a parameter for the ACO
	 * after the controller/action.
	 * @return int -1 for denied, 1 for granted, 0 for nothing found.
	 */
    public function check($aro = null, $aco = null, $role = false, $use_param = false) {
    	//Get ARO ID:
    	if(!$role) {
	    	if($aro == null) {
	    		$user_id = AuthComponent::user('id');
	    	} else if(is_array($aro)) {
	    		$user_id = $aro['User']['id'];
	    	} else {
	    		$user_id = $aro;
	    	}
	    }

		//Get ACO ID:
    	if($aco == null) {
    		$this_aco = array(
                'controller' =>  $this->_controller->params['controller'],
                'action' => $this->_controller->params['action']);
    		if($use_param && is_array($this->pass))
    			$this_aco['param'] = $this->_controller->params['pass'][0];
            $aco_id = $this->findAco($this_aco);
            if($aco_id == false)
                return 0;
    	} else if(is_array($aco)) {
    		$aco_id = $this->findAco($aco);
            if($aco_id == false)
                return 0;
    	} else {
    		$aco_id = $aco;
    	}

        //Now find the permission if both an ARO and ACO were resolved.
		if($role) 
			return $this->checkRolePermission($aro, $aco_id);
		else
			return $this->checkPermission($user_id, $aco_id);
    }

}
