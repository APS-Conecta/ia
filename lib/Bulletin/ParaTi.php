<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use OCA\Ia\ResponseDefinitions;

/**
 * «Para ti» (CONTEXT.md; gestion-aio#162), everyone's start page: the Personal lists of the latest
 * Edition of each Bulletin the person reads (a Reader group's member; editing one isn't reading it),
 * merged, the protocols about to expire in them, and those Editions. All of it comes from data, not
 * from the Model.
 *
 * @psalm-import-type IaParaTi from ResponseDefinitions
 */
class ParaTi {
	public function __construct(
		private Bulletins $bulletins,
		private Editions $editions,
	) {
	}

	/** @return IaParaTi */
	public function of(string $userId): array {
		$latest = $this->editions->latest($userId, true);
		$meetings = [];
		$documents = [];
		$expiring = [];
		foreach ($latest as $item) {
			$edition = $this->editions->one($userId, $item['id']);
			foreach ($edition['personal']['meetings'] as $meeting) {
				$meetings[$meeting['id']] = $meeting;
			}
			foreach ($edition['personal']['documents'] as $document) {
				$documents[$document['fileId']] = $document;
			}
			foreach ($edition['expiring'] as $document) {
				$expiring[$document['fileId']] = $document;
			}
		}
		usort($meetings, static fn (array $a, array $b): int => [$a['start'], $a['title']] <=> [$b['start'], $b['title']]);
		usort($expiring, static fn (array $a, array $b): int => $a['expires'] <=> $b['expires']);
		return [
			'bulletins' => $this->bulletins->all($userId, true),
			'editions' => $latest,
			'meetings' => $meetings,
			'documents' => array_values($documents),
			'expiring' => $expiring,
		];
	}
}
