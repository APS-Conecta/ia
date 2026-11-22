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
use OCA\Ia\Db\Reading;
use OCA\Ia\Forbidden;
use OCA\Ia\Reading\Readings;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Review\Reviews;
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
 * @psalm-import-type IaReview from ResponseDefinitions
 */
class Documents {
	public const FILTERS = ['all', 'changed', 'expiring', 'findings', 'cargo'];
	private const CHANGED_DAYS = 7;

	public function __construct(
		private Access $access,
		private BulletinMapper $bulletins,
		private Bulletins $setUp,
		private Editions $editions,
		private ActionMapper $actions,
		private Readings $readings,
		private Reviews $reviews,
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
		$cargos = $this->settings->cargos();
		$mine = array_flip($this->personal->cargosOf($userId, $cargos));
		// Each row, and whether its Reading names one of the Editor's Cargos.
		$rows = [];
		foreach ($this->watched($userId) as $file) {
			$reading = $this->readings->of($file->getId(), $file->getEtag());
			$named = self::named($reading, $cargos);
			$rows[] = [$this->item($userId, $file, $reading, $named, $this->reviews->latest($userId, $file)), array_intersect_key($named, $mine) !== []];
		}
		usort($rows, static fn (array $a, array $b): int => [mb_strtolower($a[0]['name']), $a[0]['fileId']] <=> [mb_strtolower($b[0]['name']), $b[0]['fileId']]);
		$counts = [];
		foreach (self::FILTERS as $name) {
			$counts[$name] = count(array_filter($rows, fn (array $row): bool => $this->keeps($name, ...$row)));
		}
		/** @var array{all: int, changed: int, expiring: int, findings: int, cargo: int} $counts */
		return [
			'documents' => array_values(array_column(array_filter($rows, fn (array $row): bool => $this->keeps($filter, ...$row)), 0)),
			'counts' => $counts,
		];
	}

	/**
	 * @return IaDocument one watched document: its row, its Reading's summary, its latest Review,
	 *                    and the Editions of the Bulletins $userId sees that list it
	 * @throws Forbidden when $userId is no Editor
	 * @throws DoesNotExistException when it isn't a document their groups watch that they can open
	 */
	public function one(string $userId, int $fileId): array {
		$file = $this->watched($userId)[$fileId] ?? throw new DoesNotExistException('No such document');
		$reading = $this->readings->of($file->getId(), $file->getEtag());
		$review = $this->reviews->latest($userId, $file);
		$read = $reading?->getStatus() === Reading::READ;
		return $this->item($userId, $file, $reading, self::named($reading, $this->settings->cargos()), $review) + [
			'summary' => $read ? $reading->getSummary() : null,
			'readAt' => $reading?->getReadAt(),
			'latestReview' => $review,
			'appearsIn' => $this->editions->appearances($userId, $fileId),
		];
	}

	/**
	 * @return array<int, File> the documents $userId's Editor groups watch that $userId
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

	/**
	 * @param array<string, string> $named the Cargos its Reading names that are still on the list
	 * @param ?IaReview $review its latest Review that $userId sees
	 * @return IaDocumentItem
	 */
	private function item(string $userId, File $file, ?Reading $reading, array $named, ?array $review): array {
		$read = $reading?->getStatus() === Reading::READ;
		return [
			'fileId' => $file->getId(),
			'name' => $file->getName(),
			'folder' => (string)$this->root->getUserFolder($userId)->getRelativePath($file->getParent()->getPath()),
			'status' => $reading === null ? 'unread' : $reading->getStatus(),
			'expires' => $read ? $reading->getExpires() : null,
			'cargos' => array_values($named),
			'review' => $review === null ? null : ['id' => $review['id'], 'findings' => count($review['findings'] ?? [])],
			'modifiedAt' => $file->getMTime(),
		];
	}

	/**
	 * @param array<string, string> $cargos the Cargo list (Settings::cargos)
	 * @return array<string, string> the Cargos a read Reading names that are still on the list, as an Edition shows them
	 */
	private static function named(?Reading $reading, array $cargos): array {
		return $reading?->getStatus() === Reading::READ ? Settings::listedCargos($cargos, $reading->cargoList()) : [];
	}

	/**
	 * @param IaDocumentItem $item
	 * @param bool $mine whether its Reading names one of the Editor's Cargos
	 */
	private function keeps(string $filter, array $item, bool $mine): bool {
		$today = new DateTimeImmutable('@' . $this->time->getTime());
		return match ($filter) {
			'changed' => $item['modifiedAt'] >= $today->modify('-' . self::CHANGED_DAYS . ' days')->getTimestamp(),
			'expiring' => $item['status'] === Reading::READ
				&& ($item['expires'] === null || $item['expires'] <= $today->modify('+' . Editions::EXPIRING_DAYS . ' days')->format('Y-m-d')),
			'findings' => ($item['review']['findings'] ?? 0) > 0,
			'cargo' => $mine,
			default => true,
		};
	}
}
