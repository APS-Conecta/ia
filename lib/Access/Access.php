<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Access;

use OCA\Ia\Settings\Settings;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * What a person can open and who they are, checked live, never remembered (ia ADR 0001): a file
 * is theirs to read only while their own Files reach it, through a share or a Team folder
 * included; they are an Editor only while one of their groups is an Editor group.
 */
class Access {
	public function __construct(
		private IRootFolder $root,
		private IGroupManager $groups,
		private IUserManager $users,
		private Settings $settings,
	) {
	}

	/** @return list<string> the Editor groups $userId belongs to now: none for anyone else */
	public function editorGroups(string $userId): array {
		$user = $this->users->get($userId);
		return $user === null ? [] : array_values(array_intersect($this->settings->editorGroups(), $this->groups->getUserGroupIds($user)));
	}

	/** The file $fileId as $userId can open it now, or null. */
	public function file(string $userId, int $fileId): ?File {
		try {
			$node = $this->root->getUserFolder($userId)->getFirstNodeById($fileId);
		} catch (NotPermittedException) {
			return null;
		}
		return $node instanceof File && $node->isReadable() ? $node : null;
	}
}
