<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Access;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Settings\Settings;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsPublic;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Settings\IManager as ISettingsManager;

/**
 * What a person can open and who they are, checked live, never remembered (ia ADR 0001): a file
 * is theirs to read only while their own Files reach it, through a share or a Team folder
 * included; a calendar only while it is among their calendars; they are an Editor only while one
 * of their groups is an Editor group.
 */
class Access {
	public function __construct(
		private IRootFolder $root,
		private IGroupManager $groups,
		private IUserManager $users,
		private Settings $settings,
		private ICalendarManager $calendars,
		private ISettingsManager $adminSettings,
	) {
	}

	/** @return list<IUser> the group's members who can sign in now */
	/** Whether $userId may open «Estado»: an admin, or a member of a group the «IA» admin page is delegated to. */
	public function admin(string $userId): bool {
		$user = $this->users->get($userId);
		return $user !== null && $this->adminSettings->getAllowedAdminSettings(Application::APP_ID, $user) !== [];
	}

	public function members(string $groupId): array {
		return array_values(array_filter($this->groups->get($groupId)?->getUsers() ?? [], static fn (IUser $user): bool => $user->isEnabled()));
	}

	/**
	 * The folder or file $fileId as $userId can open it now, of the $type asked for ('folder' or
	 * 'file'), or null; a deleted account opens nothing.
	 */
	public function node(string $userId, int $fileId, string $type): ?Node {
		if (!$this->users->userExists($userId)) {
			return null;
		}
		try {
			$node = $this->root->getUserFolder($userId)->getFirstNodeById($fileId);
		} catch (NotPermittedException) {
			return null;
		}
		$fits = $type === 'folder' ? $node instanceof Folder : $node instanceof File;
		return $fits && $node->isReadable() ? $node : null;
	}

	/** Whether $userId can open the folder, document or calendar $id now. */
	public function opens(string $userId, string $type, string $id): bool {
		return $type === 'calendar' ? $this->calendar($userId, $id) !== null : $this->node($userId, (int)$id, $type) !== null;
	}

	/** @return list<string> the names of the group's active members who can't open the folder, document or calendar $id now */
	public function lockedOut(string $groupId, string $type, string $id): array {
		$out = array_filter($this->members($groupId), fn (IUser $user): bool => !$this->opens($user->getUID(), $type, $id));
		return array_values(array_map(static fn (IUser $user): string => $user->getDisplayName(), $out));
	}

	/** Whether the file $fileId is still in someone's Files: one in a trash bin is gone. */
	public function exists(int $fileId): bool {
		foreach ($this->root->getById($fileId) as $node) {
			if (preg_match('#^/[^/]+/files/#', $node->getPath()) === 1) {
				return true;
			}
		}
		return false;
	}

	/** The calendar $key among $userId's calendars now, theirs or shared with them, or null. */
	public function calendar(string $userId, string $key): ?ICalendar {
		foreach ($this->calendarsOf($userId) as $calendar) {
			if ($calendar->getKey() === $key) {
				return $calendar;
			}
		}
		return null;
	}

	/**
	 * @return list<ICalendar> $userId's calendars on this server, theirs and those shared with them.
	 * Subscriptions and federated calendars are left out: their keys are ids from other tables, so a
	 * key would no longer name one calendar (ADR 0001's live check).
	 */
	public function calendarsOf(string $userId): array {
		return array_values(array_filter($this->calendars->getCalendarsForPrincipal('principals/users/' . $userId),
			static fn (ICalendar $calendar): bool => $calendar instanceof ICalendarIsPublic));
	}

	/** How a calendar is named to a person: its display name, else its URI. */
	public static function calendarName(ICalendar $calendar): string {
		return $calendar->getDisplayName() ?? $calendar->getUri();
	}

	/** @return list<string> the Editor groups $userId belongs to now: none for anyone else */
	public function editorGroups(string $userId): array {
		return array_values(array_intersect($this->settings->editorGroups(), $this->groupsOf($userId)));
	}

	/** @return list<string> the groups $userId belongs to now */
	public function groupsOf(string $userId): array {
		$user = $this->users->get($userId);
		return $user === null ? [] : array_values($this->groups->getUserGroupIds($user));
	}

	/** The file $fileId as $userId can open it now, or null. */
	public function file(string $userId, int $fileId): ?File {
		$node = $this->node($userId, $fileId, 'file');
		return $node instanceof File ? $node : null;
	}
}
