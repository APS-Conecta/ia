<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Document;

use DateTimeImmutable;
use OCA\Ia\Access\Access;
use OCA\Ia\Bulletin\Bulletins;
use OCA\Ia\Bulletin\Editions;
use OCA\Ia\Bulletin\PersonalList;
use OCA\Ia\Db\ActionMapper;
use OCA\Ia\Db\BulletinMapper;
use OCA\Ia\Db\EditionMapper;
use OCA\Ia\Db\Reading;
use OCA\Ia\Db\Report;
use OCA\Ia\Db\ReportMapper;
use OCA\Ia\Forbidden;
use OCA\Ia\Reading\Readings;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * «Documentos» (APS-Conecta/ia#15): the documents an Editor's groups watch, those in the Sources of
 * their Bulletins and in the folders of their Automatic actions, each with its Reading and latest
 * Review, so that an Editor spots problems across them. Every row is checked live: an Editor sees
 * only documents they can open now.
 *
 * A document Source is listed whatever its format, and under a folder the formats «IA» reads, as
 * an Edition lists them. A version no Edition has read yet is «unread»: Readings come only from
 * Editions, so a document only an Automatic action watches stays unread.
 *
 * Filters: «Nuevos y cambiados» (changed in the last week), «Por vencer» (a Reading that expires
 * within 30 days, or found no date), «Con hallazgos» (the latest Review found something) and
 * «Para mi cargo» (the Reading names one of the Editor's Cargo groups).
 *
 * @psalm-import-type IaDocumentItem from ResponseDefinitions
 * @psalm-import-type IaDocument from ResponseDefinitions
 * @psalm-import-type IaDocuments from ResponseDefinitions
 */
class Documents {
	public const FILTERS = ['all', 'changed', 'expiring', 'findings', 'cargo'];
	private const CHANGED_DAYS = 7;

	public function __construct(
		private Access $access,
		private BulletinMapper $bulletins,
		private Bulletins $setUp,
		private Editions $editions,
		private EditionMapper $editionMapper,
		private ActionMapper $actions,
		private Readings $readings,
		private ReportMapper $reports,
		private Settings $settings,
		private PersonalList $personal,
		private IRootFolder $root,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @return IaDocuments the documents $filter keeps, by name, and how many each filter keeps
	 * @throws Forbidden when $userId is no Editor
	 * @throws Refused when $filter isn't one
	 */
	public function all(string $userId, string $filter): array {
		if (!in_array($filter, self::FILTERS, true)) {
			throw new Refused('Ese filtro no existe.');
		}
		$items = array_map(fn (File $file): array => $this->item($userId, $file), array_values($this->watched($userId)));
		usort($items, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['fileId']] <=> [mb_strtolower($b['name']), $b['fileId']]);
		$mine = $this->personal->cargosOf($userId, $this->settings->cargos());
		$counts = [];
		foreach (self::FILTERS as $name) {
			$counts[$name] = count(array_filter($items, fn (array $item): bool => $this->keeps($name, $item, $mine)));
		}
		/** @var array{all: int, changed: int, expiring: int, findings: int, cargo: int} $counts */
		return [
			'documents' => array_values(array_map(static fn (array $item): array => array_diff_key($item, ['cargoIds' => 0]),
				array_filter($items, fn (array $item): bool => $this->keeps($filter, $item, $mine)))),
			'counts' => $counts,
		];
	}

	/**
	 * @return IaDocument one watched document: its row, its Reading's summary, and the latest
	 *                    Edition of each Bulletin $userId sees that it appears in
	 * @throws Forbidden when $userId is no Editor
	 * @throws DoesNotExistException when it isn't a document their groups watch that they can open
	 */
	public function one(string $userId, int $fileId): array {
		$file = $this->watched($userId)[$fileId] ?? throw new DoesNotExistException('No such document');
		$reading = $this->readings->of($file->getId(), $file->getEtag());
		$read = $reading?->getStatus() === Reading::READ;
		return array_diff_key($this->item($userId, $file), ['cargoIds' => 0]) + [
			'summary' => $read ? $reading->getSummary() : null,
			'readAt' => $reading?->getReadAt(),
			'appearsIn' => $this->appearances($userId, $fileId),
		];
	}

	/**
	 * @return array<int, File> the documents «IA» reads that $userId's Editor groups watch and $userId
	 *                          can open now, by file id
	 * @throws Forbidden when $userId is no Editor
	 */
	private function watched(string $userId): array {
		$groups = $this->access->editorGroups($userId);
		if ($groups === []) {
			throw new Forbidden('Solo los editores ven «Documentos».');
		}
		$files = [];
		foreach ($this->bulletins->all() as $bulletin) {
			if (in_array($bulletin->getEditorGroup(), $groups, true)) {
				foreach ($this->setUp->stored($bulletin) as $source) {
					if ($source['type'] !== 'calendar') {
						$files += self::byId(TextReader::documents($this->access->node($userId, (int)$source['id'], $source['type'])));
					}
				}
			}
		}
		foreach ($this->actions->all() as $action) {
			if (in_array($action->getEditorGroup(), $groups, true)) {
				$files += self::byId(TextReader::documents($this->access->node($userId, $action->getFolder(), 'folder')));
			}
		}
		return $files;
	}

	/**
	 * @param list<File> $files
	 * @return array<int, File>
	 */
	private static function byId(array $files): array {
		return array_combine(array_map(static fn (File $file): int => $file->getId(), $files), $files);
	}

	/** @return IaDocumentItem&array{cargoIds: list<string>} */
	private function item(string $userId, File $file): array {
		$reading = $this->readings->of($file->getId(), $file->getEtag());
		$read = $reading?->getStatus() === Reading::READ;
		// The Cargos it names that are still on the list, as an Edition shows them.
		$named = $read ? array_intersect_key($this->settings->cargos(), array_flip($reading->cargoList())) : [];
		$review = $this->review($userId, $file->getId());
		return [
			'fileId' => $file->getId(),
			'name' => $file->getName(),
			'folder' => (string)$this->root->getUserFolder($userId)->getRelativePath($file->getParent()->getPath()),
			'status' => $reading === null ? 'unread' : $reading->getStatus(),
			'expires' => $read ? $reading->getExpires() : null,
			'cargos' => array_values($named),
			'cargoIds' => array_map(strval(...), array_keys($named)),
			'review' => $review === null ? null : ['id' => $review->getId(), 'findings' => count(json_decode((string)$review->getFindings(), true) ?: [])],
			'modifiedAt' => $file->getMTime(),
		];
	}

	/** The latest done Review $userId sees of the file: its automatic one, or their own. */
	private function review(string $userId, int $fileId): ?Report {
		$done = array_filter([$this->reports->automaticOf($fileId), $this->reports->manualOf($userId, $fileId)],
			static fn (?Report $report): bool => $report?->getFindings() !== null);
		usort($done, static fn (Report $a, Report $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());
		return $done[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param list<string> $mine the Editor's Cargo group ids
	 */
	private function keeps(string $filter, array $item, array $mine): bool {
		$today = new DateTimeImmutable('@' . $this->time->getTime());
		return match ($filter) {
			'changed' => $item['modifiedAt'] >= $today->modify('-' . self::CHANGED_DAYS . ' days')->getTimestamp(),
			'expiring' => $item['status'] === Reading::READ
				&& ($item['expires'] === null || $item['expires'] <= $today->modify('+' . Editions::EXPIRING_DAYS . ' days')->format('Y-m-d')),
			'findings' => ($item['review']['findings'] ?? 0) > 0,
			'cargo' => array_intersect($item['cargoIds'], $mine) !== [],
			default => true,
		};
	}

	/** @return list<array{bulletin: array{id: int, name: string}, edition: array{id: int, date: string}}> */
	private function appearances(string $userId, int $fileId): array {
		$found = [];
		foreach ($this->bulletins->all() as $bulletin) {
			if ($this->setUp->role($userId, $bulletin) === null) {
				continue;
			}
			foreach ($this->editions->all($userId, $bulletin->getId()) as $item) {
				$files = json_decode((string)$this->editionMapper->byId($item['id'])?->getFiles(), true) ?: [];
				if (in_array($fileId, array_column($files, 'fileId'), true)) {
					$found[] = ['bulletin' => ['id' => $bulletin->getId(), 'name' => $bulletin->getName()], 'edition' => ['id' => $item['id'], 'date' => $item['date']]];
					break;
				}
			}
		}
		return $found;
	}
}
