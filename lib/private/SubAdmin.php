<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC;

use OC\Hooks\PublicEmitter;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IDBConnection;
use OCP\ISubAdminManager;

class SubAdmin extends PublicEmitter implements ISubAdminManager {

	/** @var IUserManager */
	private $userManager;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IDBConnection */
	private $dbConn;

	/**
	 * @param IUserManager $userManager
	 * @param IGroupManager $groupManager
	 * @param IDBConnection $dbConn
	 */
	public function __construct(IUserManager $userManager,
								IGroupManager $groupManager,
								IDBConnection $dbConn) {
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->dbConn = $dbConn;

		// TODO: This wont be needed since MembershipManager should handle this internally and only reason to keep this will be caching...
		// TODO: BTW, will explode with FK anyways..
		$this->userManager->listen('\OC\User', 'postDelete', function($user) {
			$this->post_deleteUser($user);
		});
		$this->groupManager->listen('\OC\Group', 'postDelete', function($group) {
			$this->post_deleteGroup($group);
		});
	}

	/**
	 * add a SubAdmin
	 * @param IUser $user user to be SubAdmin
	 * @param IGroup $group group $user becomes subadmin of
	 * @return bool
	 */
	public function createSubAdmin(IUser $user, IGroup $group) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_create-'.$user->getUID().'-'.$group->getGID(), 0, 0);
		// TODO: MembershipManager->addGroupAdmin($userId, $gid)
		$qb = $this->dbConn->getQueryBuilder();

		$qb->insert('group_admin')
			->values([
				'gid' => $qb->createNamedParameter($group->getGID()),
				'uid' => $qb->createNamedParameter($user->getUID())
			])
			->execute();

		$this->emit('\OC\SubAdmin', 'postCreateSubAdmin', [$user, $group]);
		\OC_Hook::emit("OC_SubAdmin", "post_createSubAdmin", ["gid" => $group->getGID()]);
		return true;
	}

	/**
	 * delete a SubAdmin
	 * @param IUser $user the user that is the SubAdmin
	 * @param IGroup $group the group
	 * @return bool
	 */
	public function deleteSubAdmin(IUser $user, IGroup $group) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_delete_subadmin-'.$user->getUID().'-'.$group->getGID(), 0, 0);
		// TODO: MembershipManager->deleteGroupAdmin($userId, $gid)
		$qb = $this->dbConn->getQueryBuilder();

		$qb->delete('group_admin')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($group->getGID())))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($user->getUID())))
			->execute();

		$this->emit('\OC\SubAdmin', 'postDeleteSubAdmin', [$user, $group]);
		\OC_Hook::emit("OC_SubAdmin", "post_deleteSubAdmin", ["gid" => $group->getGID()]);
		return true;
	}

	/**
	 * get groups of a SubAdmin
	 * @param IUser $user the SubAdmin
	 * @return IGroup[]
	 */
	public function getSubAdminsGroups(IUser $user) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_get_groups-'.$user->getUID(), 0, 0);
		// TODO: MembershipManager->->getAdminBackendGroups($userId) and convert to IGroup
		$qb = $this->dbConn->getQueryBuilder();

		$result = $qb->select('gid')
			->from('group_admin')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($user->getUID())))
			->execute();

		$groups = [];
		while($row = $result->fetch()) {
			$group = $this->groupManager->get($row['gid']);
			if(!is_null($group)) {
				$groups[] = $group;
			}
		}
		$result->closeCursor();

		return $groups;
	}

	/**
	 * get SubAdmins of a group
	 * @param IGroup $group the group
	 * @return IUser[]
	 */
	public function getGroupsSubAdmins(IGroup $group) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_get_group_subadmins-'.$group->getGID(), 0, 0);
		// TODO: Use MembershipManager->getGroupAdminAccounts($gid)
		$qb = $this->dbConn->getQueryBuilder();

		$result = $qb->select('uid')
			->from('group_admin')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($group->getGID())))
			->execute();

		$users = [];
		while($row = $result->fetch()) {
			$user = $this->userManager->get($row['uid']);
			if(!is_null($user)) {
				$users[] = $user;
			}
		}
		$result->closeCursor();

		return $users;
	}

	/**
	 * get all SubAdmins
	 * @return array
	 */
	public function getAllSubAdmins() {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_get_all', 0, 0);
		// TODO: Use MembershipManager->getAdminAccounts()
		$qb = $this->dbConn->getQueryBuilder();

		$result = $qb->select('*')
			->from('group_admin')
			->execute();

		$subadmins = [];
		while($row = $result->fetch()) {
			$user = $this->userManager->get($row['uid']);
			$group = $this->groupManager->get($row['gid']);
			if(!is_null($user) && !is_null($group)) {
				$subadmins[] = [
					'user'  => $user,
					'group' => $group
				];
			}
		}
		$result->closeCursor();

		return $subadmins;
	}

	/**
	 * checks if a user is a SubAdmin of a group
	 * @param IUser $user
	 * @param IGroup $group
	 * @return bool
	 */
	public function isSubAdminofGroup(IUser $user, IGroup $group) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_is_subadmin-'.$user->getUID().'-'.$group->getGID(), 0, 0);
		// TODO: Use MembershipManager->isGroupAdmin($userId, $gid)
		$qb = $this->dbConn->getQueryBuilder();

		/*
		 * Primary key is ('gid', 'uid') so max 1 result possible here
		 */
		$result = $qb->select('*')
			->from('group_admin')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($group->getGID())))
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($user->getUID())))
			->execute();

		$fetch =  $result->fetch();
		$result->closeCursor();
		$result = !empty($fetch) ? true : false;

		return $result;
	}

	/**
	 * checks if a user is a SubAdmin
	 * @param IUser $user
	 * @return bool
	 */
	public function isSubAdmin(IUser $user) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_issubadmin-'.$user->getUID(), 0, 0);
		// TODO: Use MembershipManager->getAdminBackendGroups($userId)
		// Check if the user is already an admin
		if ($this->groupManager->isAdmin($user->getUID())) {
			return true;
		}

		$qb = $this->dbConn->getQueryBuilder();

		$result = $qb->select('gid')
			->from('group_admin')
			->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($user->getUID())))
			->setMaxResults(1)
			->execute();

		$isSubAdmin = $result->fetch();
		$result->closeCursor();

		$result = $isSubAdmin === false ? false : true;

		return $result;
	}

	/**
	 * checks if a user is a accessible by a subadmin
	 * @param IUser $subadmin
	 * @param IUser $user
	 * @return bool
	 */
	public function isUserAccessible($subadmin, $user) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_is_user_accessible-'.$user->getUID().'-'.$subadmin->getUID(), 0, 0);
		//TODO: Optimize it a bit
		if(!$this->isSubAdmin($subadmin)) {
			return false;
		}
		if($this->groupManager->isAdmin($user->getUID())) {
			return false;
		}
		$accessibleGroups = $this->getSubAdminsGroups($subadmin);
		foreach($accessibleGroups as $accessibleGroup) {
			if($accessibleGroup->inGroup($user)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * TODO: This wont be needed since MembershipManager should handle this internally in IUser
	 *
	 * delete all SubAdmins by $user
	 * @param IUser $user
	 * @return boolean
	 */
	private function post_deleteUser($user) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_post_delete_user-'.$user->getUID(), 0, 0);
		$qb = $this->dbConn->getQueryBuilder();

		$qb->delete('group_admin')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($user->getUID())))
			->execute();

		return true;
	}

	/**
	 * TODO: This wont be needed since MembershipManager should handle this internally in IGroup
	 *
	 * delete all SubAdmins by $group
	 * @param IGroup $group
	 * @return boolean
	 */
	private function post_deleteGroup($group) {
		\OC::$server->getEventLogger()->log((rand()), 'subadmin_post_delete_group-'.$group->getGID(), 0, 0);
		$qb = $this->dbConn->getQueryBuilder();

		$qb->delete('group_admin')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($group->getGID())))
			->execute();

		return true;
	}
}