<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Ia\Access\Access;
use OCA\Ia\Db\Bulletin;
use OCA\Ia\Db\BulletinMapper;
use OCA\Ia\Db\Edition;
use OCA\Ia\Db\EditionMapper;
use OCA\Ia\Db\Reading;
use OCA\Ia\Forbidden;
use OCA\Ia\Reading\Readings;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;

/**
 * Editions as their Readers read them (gestion-aio#162, Bulletins).
 *
 * - **Published only**: nothing shows while an Edition is written.
 * - **Seen only by who can open what it read**: an Editor or Reader of its Bulletin who can open
 *   every file it read that is still in someone's Files; to anyone else it doesn't exist.
 * - **Its sections come from data**: «Documentos nuevos y cambiados» (the difference from the
 *   previous Edition), «Protocolos por vencer» (within 30 days of its date, and those with no
 *   date found), «Fuentes que no se pudieron leer» (scans and failures). Each document carries its
 *   Reading; the summary while the Reading is still of that version.
 * - **Each Reader's own part**, the Personal list: their meetings in the Edition's period (from its
 *   date to the next one's) and its documents whose Reading names one of their Cargo groups.
 *
 * @psalm-import-type IaEdition from ResponseDefinitions
 * @psalm-import-type IaEditionDocument from ResponseDefinitions
 * @psalm-import-type IaMeeting from ResponseDefinitions
 * @psalm-import-type IaEditionItem from ResponseDefinitions
 * @psalm-import-type IaEditionSummary from ResponseDefinitions
 */
class Editions {
	/** «Protocolos por vencer»: expiring within this many days of the Edition's date. */
	private const EXPIRING_DAYS = 30;

	public function __construct(
		private BulletinMapper $bulletins,
		private EditionMapper $editions,
		private Bulletins $setUp,
		private Readings $readings,
		private Access $access,
		private PersonalList $personal,
		private Settings $settings,
	) {
	}

	/**
	 * @return list<IaEditionSummary> the latest Edition $userId can see of each Bulletin they edit or
	 *                                read (only those they read, when $read), newest first
	 */
	public function latest(string $userId, bool $read = false): array {
		$opens = $this->opener($userId);
		$latest = [];
		foreach ($this->bulletins->all() as $bulletin) {
			if ($this->setUp->role($userId, $bulletin) === null || ($read && !$this->setUp->reads($userId, $bulletin))) {
				continue;
			}
			foreach ($this->editions->ofBulletin($bulletin->getId()) as $edition) {
				if ($edition->getStatus() === Edition::PUBLISHED && self::seenBy($edition, $opens)) {
					$latest[] = ['id' => $edition->getId(), 'date' => $edition->getDate(), 'late' => (bool)$edition->getLate(), 'bulletin' => ['id' => $bulletin->getId(), 'name' => $bulletin->getName()]];
					break;
				}
			}
		}
		usort($latest, static fn (array $a, array $b): int => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);
		return $latest;
	}

	/**
	 * @return list<IaEditionItem> the Bulletin's Editions $userId can see, newest first
	 * @throws DoesNotExistException
	 * @throws Forbidden when $userId neither edits nor reads it
	 */
	public function all(string $userId, int $bulletinId): array {
		$bulletin = $this->bulletins->byId($bulletinId) ?? throw new DoesNotExistException('No such Bulletin');
		if ($this->setUp->role($userId, $bulletin) === null) {
			throw new Forbidden('Solo sus editores y lectores ven las ediciones de un boletín.');
		}
		$opens = $this->opener($userId);
		$items = [];
		foreach ($this->editions->ofBulletin($bulletinId) as $edition) {
			if ($edition->getStatus() === Edition::PUBLISHED && self::seenBy($edition, $opens)) {
				$items[] = ['id' => $edition->getId(), 'date' => $edition->getDate(), 'late' => (bool)$edition->getLate()];
			}
		}
		return $items;
	}

	/**
	 * @return IaEdition
	 * @throws DoesNotExistException when there is none $userId can see
	 */
	public function one(string $userId, int $id): array {
		$edition = $this->editions->byId($id);
		$bulletin = $edition === null ? null : $this->bulletins->byId($edition->getBulletinId());
		if ($edition?->getStatus() !== Edition::PUBLISHED || $bulletin === null || $this->setUp->role($userId, $bulletin) === null
			|| !self::seenBy($edition, $this->opener($userId))) {
			throw new DoesNotExistException('No such Edition');
		}
		$cargos = $this->settings->cargos();
		$readerCargos = $this->personal->cargosOf($userId, $cargos);
		$stored = self::files($edition);
		$documents = array_map(fn (array $document): array => $this->document($document, $cargos), $stored);
		$personal = array_filter($documents, static fn (array $document, int $i): bool => $document['status'] === Reading::READ
			&& array_intersect($stored[$i]['cargos'], $readerCargos) !== [], ARRAY_FILTER_USE_BOTH);
		$limit = (new DateTimeImmutable($edition->getDate()))->modify('+' . self::EXPIRING_DAYS . ' days')->format('Y-m-d');
		$read = array_filter($documents, static fn (array $document): bool => $document['status'] === Reading::READ);
		$expiring = array_filter($read, static fn (array $document): bool => $document['expires'] !== null && $document['expires'] <= $limit);
		usort($expiring, static fn (array $a, array $b): int => $a['expires'] <=> $b['expires']);
		return [
			'id' => $edition->getId(),
			'bulletin' => ['id' => $bulletin->getId(), 'name' => $bulletin->getName()],
			'date' => $edition->getDate(),
			'late' => (bool)$edition->getLate(),
			'resumen' => (string)$edition->getResumen(),
			'changed' => array_values(array_filter($documents, static fn (array $document): bool => $document['new'])),
			'expiring' => $expiring,
			'undated' => array_values(array_filter($read, static fn (array $document): bool => $document['expires'] === null)),
			'unreadable' => array_values(array_filter($documents, static fn (array $document): bool => $document['status'] !== Reading::READ)),
			'personal' => ['meetings' => $this->meetings($userId, $edition), 'documents' => array_values($personal)],
		];
	}

	/** @return list<IaMeeting> $userId's meetings in the Edition's period, from its date to its end, in the server's timezone */
	private function meetings(string $userId, Edition $edition): array {
		$timezone = new DateTimeZone($this->settings->timezone());
		return $this->personal->meetings($userId, new DateTimeImmutable($edition->getDate(), $timezone), new DateTimeImmutable($edition->getUntil(), $timezone));
	}

	/** @return array<int, string> the versions the Bulletin's last Edition published before $edition read, by file id */
	public function previous(Edition $edition): array {
		foreach ($this->editions->ofBulletin($edition->getBulletinId()) as $earlier) {
			if ($earlier->getStatus() === Edition::PUBLISHED && $earlier->getDate() < $edition->getDate()) {
				return array_column(self::files($earlier), 'etag', 'fileId');
			}
		}
		return [];
	}

	/** @return list<string> the Bulletin's Readers who can see the Edition */
	public function readers(Bulletin $bulletin, Edition $edition): array {
		$readers = array_map(static fn (IUser $user): string => $user->getUID(), $this->setUp->readers($bulletin));
		return array_values(array_filter($readers, fn (string $reader): bool => self::seenBy($edition, $this->opener($reader))));
	}

	/**
	 * Whether $userId can open a file, asked once per file: an Edition's list repeats them.
	 *
	 * @return callable(int): bool
	 */
	private function opener(string $userId): callable {
		$known = [];
		return function (int $fileId) use ($userId, &$known): bool {
			return $known[$fileId] ??= $this->access->file($userId, $fileId) !== null || !$this->access->exists($fileId);
		};
	}

	/** @param callable(int): bool $opens */
	private static function seenBy(Edition $edition, callable $opens): bool {
		foreach (self::files($edition) as $document) {
			if (!$opens($document['fileId'])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A document as an Edition shows it, with its Reading; the summary only while the Reading is of
	 * the version the Edition read, and its Cargo groups by name, those still on the list.
	 *
	 * @param array<string, mixed> $document as stored
	 * @param array<string, string> $cargos the Cargo list, name by group id (Settings::cargos)
	 * @return IaEditionDocument
	 */
	private function document(array $document, array $cargos): array {
		$current = $this->readings->of($document['fileId'], $document['etag']);
		return [
			'fileId' => $document['fileId'],
			'name' => $document['name'],
			'new' => $document['new'],
			'status' => $document['status'],
			'summary' => $current?->getSummary(),
			'readAt' => $current?->getReadAt(),
			'expires' => $document['expires'],
			'cargos' => array_values(array_intersect_key($cargos, array_flip($document['cargos']))),
		];
	}

	/** @return list<array<string, mixed>> the documents the Edition read, as stored */
	private static function files(Edition $edition): array {
		return json_decode((string)$edition->getFiles(), true) ?: [];
	}
}
