<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace OC\Group;

use OCP\IGroup;
use OCP\IUser;

class Group implements IGroup {

	/**
	 * @var string $displayName
	 */
	private $displayName;

	/**
	 * @var string $id
	 */
	private $gid;

	/**
	 * @var \OC\User\User[] $users
	 */
	private $users = [];

	/**
	 * @var bool $usersLoaded
	 */
	private $usersLoaded;

	/**
	 * @var \OC\Group\Backend[]|\OC\Group\Database[] $backend
	 */
	private $backends;

	/**
	 * @var \OC\Hooks\PublicEmitter $emitter
	 */
	private $emitter;

	/**
	 * @var \OC\User\Manager $userManager
	 */
	private $userManager;

	/**
	 * // TODO: use BackendGroup here
	 * 
	 * @param string $gid
	 * @param \OC\Group\Backend[] $backends
	 * @param \OC\User\Manager $userManager
	 * @param \OC\Hooks\PublicEmitter $emitter
	 * @param string $displayName
	 */
	public function __construct($gid, $backends, $userManager, $emitter = null, $displayName = null) {
		$this->gid = $gid;
		$this->backends = $backends;
		$this->userManager = $userManager;
		$this->emitter = $emitter;
		$this->displayName = $displayName;
	}

	public function getGID() {
		return $this->gid;
	}

	public function getDisplayName() {
		if (is_null($this->displayName)) {
			return $this->gid;
		}
		return $this->displayName;
	}

	/**
	 * get all users in the group
	 *
	 * @return \OC\User\User[]
	 */
	public function getUsers() {
		\OC::$server->getEventLogger()->log((rand()), 'group_get_users-'.$this->gid, 0, 0);
		// TODO: Use MembershipManager->getGroupUserAccounts($gid)
		if ($this->usersLoaded) {
			return $this->users;
		}

		$userIds = [];
		foreach ($this->backends as $backend) {
			$diff = array_diff(
				$backend->usersInGroup($this->gid),
				$userIds
			);
			if ($diff) {
				$userIds = array_merge($userIds, $diff);
			}
		}

		$this->users = $this->getVerifiedUsers($userIds);
		$this->usersLoaded = true;
		return $this->users;
	}

	/**
	 * check if a user is in the group
	 *
	 * @param IUser $user
	 * @return bool
	 */
	public function inGroup($user) {
		\OC::$server->getEventLogger()->log((rand()), 'group_in_group-'.$this->gid.'-'.$user->getUID(), 0, 0);
		// TODO: Use MembershipManager->isGroupUser($userId, $gid)
		if (isset($this->users[$user->getUID()])) {
			return true;
		}
		foreach ($this->backends as $backend) {
			if ($backend->inGroup($user->getUID(), $this->gid)) {
				$this->users[$user->getUID()] = $user;
				return true;
			}
		}
		return false;
	}

	/**
	 * add a user to the group
	 *
	 * @param \OC\User\User $user
	 */
	public function addUser($user) {
		\OC::$server->getEventLogger()->log((rand()), 'group_add_user-'.$this->gid.'-'.$user->getUID(), 0, 0);
		// TODO: Use MembershipManager->addGroupMember($userId, $gid)
		if ($this->inGroup($user)) {
			return;
		}

		if ($this->emitter) {
			$this->emitter->emit('\OC\Group', 'preAddUser', [$this, $user]);
		}
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(\OC\Group\Backend::ADD_TO_GROUP)) {
				$backend->addToGroup($user->getUID(), $this->gid);
				if ($this->users) {
					$this->users[$user->getUID()] = $user;
				}
				if ($this->emitter) {
					$this->emitter->emit('\OC\Group', 'postAddUser', [$this, $user]);
				}
				return;
			}
		}
	}

	/**
	 * remove a user from the group
	 *
	 * @param \OC\User\User $user
	 */
	public function removeUser($user) {
		\OC::$server->getEventLogger()->log((rand()), 'group_remove_user-'.$this->gid.'-'.$user->getUID(), 0, 0);
		// TODO: Use MembershipManager->removeGroupMember($userId, $gid)
		$result = false;
		if ($this->emitter) {
			$this->emitter->emit('\OC\Group', 'preRemoveUser', [$this, $user]);
		}
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(\OC\Group\Backend::REMOVE_FROM_GOUP) and $backend->inGroup($user->getUID(), $this->gid)) {
				$backend->removeFromGroup($user->getUID(), $this->gid);
				$result = true;
			}
		}
		if ($result) {
			if ($this->emitter) {
				$this->emitter->emit('\OC\Group', 'postRemoveUser', [$this, $user]);
			}
			if ($this->users) {
				foreach ($this->users as $index => $groupUser) {
					if ($groupUser->getUID() === $user->getUID()) {
						unset($this->users[$index]);
						return;
					}
				}
			}
		}
	}

	/**
	 * search for users in the group by userid
	 *
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchUsers($search, $limit = null, $offset = null) {
		\OC::$server->getEventLogger()->log((rand()), 'group_search_users'.'-'.$this->gid.'-'.$search, 0, 0);
		// TODO: Use MembershipManager->find($gid, $search, $searchLimit, $searchOffset)
		$users = [];
		foreach ($this->backends as $backend) {
			$userIds = $backend->usersInGroup($this->gid, $search, $limit, $offset);
			$users += $this->getVerifiedUsers($userIds);
			if (!is_null($limit) and $limit <= 0) {
				return array_values($users);
			}
		}
		return array_values($users);
	}

	/**
	 * returns the number of users matching the search string
	 *
	 * @param string $search
	 * @return int|bool
	 */
	public function count($search = '') {
		\OC::$server->getEventLogger()->log((rand()), 'group_count'.'-'.$this->gid.'-'.$search, 0, 0);
		// TODO: Use MembershipAdmin->count($gid, $search, $searchLimit, $searchOffset)
		$users = false;
		foreach ($this->backends as $backend) {
			if($backend->implementsActions(\OC\Group\Backend::COUNT_USERS)) {
				if($users === false) {
					//we could directly add to a bool variable, but this would
					//be ugly
					$users = 0;
				}
				$users += $backend->countUsersInGroup($this->gid, $search);
			}
		}
		return $users;
	}

	/**
	 * search for users in the group by displayname
	 *
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return \OC\User\User[]
	 */
	public function searchDisplayName($search, $limit = null, $offset = null) {
		\OC::$server->getEventLogger()->log((rand()), 'group_search_displayname'.'-'.$this->gid.'-'.$search, 0, 0);
		// TODO: Use MembershipManager->find($gid, $search, $searchLimit, $searchOffset)
		$users = [];
		foreach ($this->backends as $backend) {
			$userIds = $backend->usersInGroup($this->gid, $search, $limit, $offset);
			$users = $this->getVerifiedUsers($userIds);
			if (!is_null($limit) and $limit <= 0) {
				return array_values($users);
			}
		}
		return array_values($users);
	}

	/**
	 * delete the group
	 *
	 * @return bool
	 */
	public function delete() {
		\OC::$server->getEventLogger()->log((rand()), 'group_delete'.'-'.$this->gid, 0, 0);
		// TODO: Use MembershipManager->removeGroupMembers($gid) and GroupMapper->delete to do the job
		// Prevent users from deleting group admin
		if ($this->getGID() === 'admin') {
			return false;
		}

		$result = false;
		if ($this->emitter) {
			$this->emitter->emit('\OC\Group', 'preDelete', [$this]);
		}
		foreach ($this->backends as $backend) {
			if ($backend->implementsActions(\OC\Group\Backend::DELETE_GROUP)) {
				$result = true;
				$backend->deleteGroup($this->gid);
			}
		}
		if ($result and $this->emitter) {
			$this->emitter->emit('\OC\Group', 'postDelete', [$this]);
		}
		return $result;
	}

	/**
	 * TODO: delete, not it will be useless
	 *
	 * returns all the Users from an array that really exists
	 * @param string[] $userIds an array containing user IDs
	 * @return \OC\User\User[] an Array with the userId as Key and \OC\User\User as value
	 */
	private function getVerifiedUsers($userIds) {
		if (!is_array($userIds)) {
			return [];
		}
		$users = [];
		foreach ($userIds as $userId) {
			$user = $this->userManager->get($userId);
			if (!is_null($user)) {
				$users[$userId] = $user;
			}
		}
		return $users;
	}

	/**
	 * Returns the backend for this group
	 *
	 * @return \OC\Group\Backend
	 * @since 10.0.0
	 */
	public function getBackend() {
		\OC::$server->getEventLogger()->log((rand()), 'group_get_backend'.'-'.$this->gid, 0, 0);
		// TODO: Use GroupBackend
		// multiple backends can exist for the same group name,
		// but in practice there is only a single one, so return that one
		return $this->backends[0];
	}
}