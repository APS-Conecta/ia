<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Access;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;

/**
 * What a person can open, checked live, never remembered (ia ADR 0001): a file is theirs to
 * read only while their own Files reach it, through a share or a Team folder included.
 */
class Access {
	public function __construct(
		private IRootFolder $root,
	) {
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
