<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Ia\Access\Access;
use OCA\Ia\Db\Bulletin;
use OCA\Ia\Db\BulletinMapper;
use OCA\Ia\Db\EditionMapper;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Forbidden;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Bulletins (CONTEXT.md): an Editor group owns each one, picks its Sources, its Reader groups and
 * its schedule (gestion-aio#162, Bulletins).
 *
 * - **Only the Editor group's members** list, create, change and delete them; Readers see the
 *   ones they read.
 * - **Sources the whole Editor group can open**: a night run reads them as any of its members, so
 *   a Source one member can't open is refused, naming who.
 * - **Readers** are the Reader groups' members; the preview counts those who can open every
 *   Source, checked live, and names the others with the Sources they can't open.
 * - **Deleted** with its Editions and their Runs.
 *
 * @psalm-import-type IaBulletin from ResponseDefinitions
 * @psalm-import-type IaBulletinItem from ResponseDefinitions
 * @psalm-import-type IaGroup from ResponseDefinitions
 * @psalm-import-type IaReadersPreview from ResponseDefinitions
 * @psalm-import-type IaSource from ResponseDefinitions
 */
class Bulletins {
	use TTransactional;

	private const TYPES = ['folder', 'file', 'calendar'];
	private const NOT_THEIRS = 'Solo los miembros del grupo editor de un boletín pueden verlo o cambiarlo.';

	public function __construct(
		private BulletinMapper $bulletins,
		private EditionMapper $editions,
		private RunMapper $runs,
		private Access $access,
		private IGroupManager $groups,
		private Settings $settings,
		private IDBConnection $db,
	) {
	}

	/** @return list<IaBulletinItem> the Bulletins $userId edits or reads (only those they read, when $read), by name */
	public function all(string $userId, bool $read = false): array {
		$items = [];
		foreach ($this->bulletins->all() as $bulletin) {
			$role = $this->role($userId, $bulletin);
			if ($role !== null && (!$read || $this->reads($userId, $bulletin))) {
				$items[] = ['id' => $bulletin->getId(), 'name' => $bulletin->getName(), 'role' => $role];
			}
		}
		return $items;
	}

	/**
	 * @return IaBulletin
	 * @throws DoesNotExistException
	 * @throws Forbidden when $userId neither edits nor reads it
	 */
	public function one(string $userId, int $id): array {
		$bulletin = $this->bulletins->byId($id) ?? throw new DoesNotExistException('No such Bulletin');
		$role = $this->role($userId, $bulletin) ?? throw new Forbidden(self::NOT_THEIRS);
		return $this->view($userId, $bulletin, $role);
	}

	/**
	 * A new Bulletin of one of $userId's Editor groups.
	 *
	 * @param array<mixed> $input {name, editorGroup, sources: list<{type, id}>, readers: list<string>, schedule: {kind, day}}
	 * @return IaBulletin
	 * @throws Forbidden when $editorGroup isn't one of theirs
	 * @throws Refused when a field isn't one, or the Editor group can't open a Source
	 */
	public function create(string $userId, array $input): array {
		$group = $input['editorGroup'] ?? null;
		if (!is_string($group) || !in_array($group, $this->access->editorGroups($userId), true)) {
			throw new Forbidden('Solo creas boletines para un grupo editor tuyo.');
		}
		$bulletin = new Bulletin();
		$bulletin->setEditorGroup($group);
		$this->fill($userId, $bulletin, $input);
		$this->bulletins->insert($bulletin);
		return $this->view($userId, $bulletin, 'editor');
	}

	/**
	 * Changes a Bulletin of $userId's Editor group: its name, Sources, Readers and schedule.
	 *
	 * @param array<mixed> $input as create's, without the Editor group, which stays
	 * @return IaBulletin
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 * @throws Refused
	 */
	public function change(string $userId, int $id, array $input): array {
		$bulletin = $this->edited($userId, $id);
		$this->fill($userId, $bulletin, $input);
		$this->bulletins->update($bulletin);
		return $this->view($userId, $bulletin, 'editor');
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 */
	public function delete(string $userId, int $id): void {
		$bulletin = $this->edited($userId, $id);
		$this->atomic(function () use ($bulletin): void {
			$this->runs->forget(Run::EDITION, array_map('strval', $this->editions->forget($bulletin->getId())));
			$this->bulletins->delete($bulletin);
		}, $this->db);
	}

	/**
	 * Who a Bulletin with these Sources and Reader groups would reach, for an Editor setting one up.
	 *
	 * @param array<mixed> $input {sources, readers}
	 * @return IaReadersPreview
	 * @throws Forbidden when $userId is no Editor
	 * @throws Refused
	 */
	public function preview(string $userId, array $input): array {
		if ($this->access->editorGroups($userId) === []) {
			throw new Forbidden('Solo los editores preparan boletines.');
		}
		$sources = $this->sources($userId, $input['sources'] ?? null);
		$readers = $this->membersOf($this->readerGroups($input['readers'] ?? null));
		$leftOut = [];
		foreach ($readers as $user) {
			$cannot = $this->cannotOpen($user, $sources);
			if ($cannot !== []) {
				$leftOut[] = ['id' => $user->getUID(), 'name' => $user->getDisplayName(), 'sources' => array_column($cannot, 'name')];
			}
		}
		return ['readers' => count($readers) - count($leftOut), 'leftOut' => $leftOut];
	}

	/** @return list<IUser> the Bulletin's Readers: its Reader groups' active members, each once */
	public function readers(Bulletin $bulletin): array {
		return $this->membersOf(self::decode($bulletin->getReaders()));
	}

	/**
	 * Who a Bulletin's Run acts as: the first active member of its Editor group, by id, who can open
	 * every Source now (a folder or document no longer in anyone's Files is no Source any more);
	 * null when none can.
	 */
	public function actor(Bulletin $bulletin): ?IUser {
		$sources = array_values(array_filter($this->stored($bulletin), fn (array $source): bool => $source['type'] === 'calendar' || $this->access->exists((int)$source['id'])));
		$members = $this->access->members($bulletin->getEditorGroup());
		usort($members, static fn (IUser $a, IUser $b): int => strcmp($a->getUID(), $b->getUID()));
		foreach ($members as $member) {
			if ($this->cannotOpen($member, $sources) === []) {
				return $member;
			}
		}
		return null;
	}

	/** @return list<IaSource> the Bulletin's Sources, named as its actor or anyone sees them */
	public function stored(Bulletin $bulletin): array {
		return array_map(static fn (array $source): array => ['type' => $source['type'], 'id' => (string)$source['id'], 'name' => ''], self::decode($bulletin->getSources()));
	}

	/**
	 * The Bulletin $id, when $userId is in its Editor group.
	 *
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 */
	private function edited(string $userId, int $id): Bulletin {
		$bulletin = $this->bulletins->byId($id) ?? throw new DoesNotExistException('No such Bulletin');
		if ($this->role($userId, $bulletin) !== 'editor') {
			throw new Forbidden(self::NOT_THEIRS);
		}
		return $bulletin;
	}

	/** 'editor' when $userId is in its Editor group (still an Editor group), 'reader' when in a Reader group, else null. */
	public function role(string $userId, Bulletin $bulletin): ?string {
		if (in_array($bulletin->getEditorGroup(), $this->access->editorGroups($userId), true)) {
			return 'editor';
		}
		return $this->reads($userId, $bulletin) ? 'reader' : null;
	}

	/** Whether $userId is in one of the Bulletin's Reader groups. */
	public function reads(string $userId, Bulletin $bulletin): bool {
		return array_intersect(self::decode($bulletin->getReaders()), $this->access->groupsOf($userId)) !== [];
	}

	/**
	 * Checks $input and sets the Bulletin's name, Sources, Readers and schedule from it.
	 *
	 * @param array<mixed> $input
	 * @throws Refused
	 */
	private function fill(string $userId, Bulletin $bulletin, array $input): void {
		$name = trim((string)($input['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 200) {
			throw new Refused('Escribe un nombre para el boletín, de hasta 200 caracteres.');
		}
		$sources = $this->sources($userId, $input['sources'] ?? null);
		foreach ($sources as $source) {
			$cannot = $this->access->lockedOut($bulletin->getEditorGroup(), $source['type'], $source['id']);
			if ($cannot !== []) {
				throw new Refused('Todo el grupo editor debe poder abrir cada fuente, y «' . $source['name'] . '» no la puede abrir: ' . implode(', ', $cannot) . '.');
			}
		}
		$readers = $this->readerGroups($input['readers'] ?? null);
		$schedule = Schedule::from(is_array($input['schedule'] ?? null) ? $input['schedule'] : []);
		$bulletin->setName($name);
		$bulletin->setSources(json_encode(array_map(static fn (array $source): array => ['type' => $source['type'], 'id' => $source['id']], $sources), JSON_THROW_ON_ERROR));
		$bulletin->setReaders(json_encode($readers, JSON_THROW_ON_ERROR));
		$bulletin->setSchedule($schedule->stored());
	}

	/**
	 * The Sources in $input, each one $userId can open, with its name.
	 *
	 * @return list<IaSource>
	 * @throws Refused
	 */
	private function sources(string $userId, mixed $input): array {
		if (!is_array($input) || $input === []) {
			throw new Refused('Elige al menos una fuente: una carpeta, un documento o un calendario.');
		}
		$sources = [];
		foreach ($input as $source) {
			$named = is_array($source) ? $this->named($userId, $source['type'] ?? null, $source['id'] ?? null) : null;
			if ($named === null) {
				throw new Refused('Una de las fuentes ya no existe o no puedes abrirla.');
			}
			$sources[$named['type'] . ':' . $named['id']] = $named;
		}
		return array_values($sources);
	}

	/**
	 * The Source as $userId sees it, with its name, or null when they can't open it.
	 *
	 * @return IaSource|null
	 */
	private function named(string $userId, mixed $type, mixed $id): ?array {
		if (!in_array($type, self::TYPES, true) || (!is_string($id) && !is_int($id)) || (string)$id === ''
			|| ($type !== 'calendar' && !ctype_digit((string)$id))) {
			return null;
		}
		$calendar = $type === 'calendar' ? $this->access->calendar($userId, (string)$id) : null;
		$name = $type === 'calendar'
			? ($calendar === null ? null : Access::calendarName($calendar))
			: $this->access->node($userId, (int)$id, $type)?->getName();
		return $name === null ? null : ['type' => $type, 'id' => (string)$id, 'name' => $name];
	}

	/**
	 * The Sources of $sources that $user can't open now.
	 *
	 * @param list<IaSource> $sources
	 * @return list<IaSource>
	 */
	private function cannotOpen(IUser $user, array $sources): array {
		return array_values(array_filter($sources, fn (array $source): bool => !$this->access->opens($user->getUID(), $source['type'], $source['id'])));
	}

	/**
	 * @return list<string> the Reader groups in $input, each an existing group
	 * @throws Refused
	 */
	private function readerGroups(mixed $input): array {
		if (!is_array($input) || $input === []) {
			throw new Refused('Elige al menos un grupo de lectores.');
		}
		foreach ($input as $group) {
			if (!is_string($group) || !$this->groups->groupExists($group)) {
				throw new Refused('No existe el grupo «' . (is_string($group) ? $group : json_encode($group)) . '».');
			}
		}
		return array_values(array_unique($input));
	}

	/** @return IaBulletin A Reader's has neither Sources nor Reader groups: only an Editor sets them. */
	private function view(string $userId, Bulletin $bulletin, string $role): array {
		$sources = [];
		foreach ($role === 'editor' ? self::decode($bulletin->getSources()) : [] as $source) {
			$sources[] = $this->named($userId, $source['type'], $source['id'])
				?? ['type' => $source['type'], 'id' => (string)$source['id'], 'name' => 'Ya no puedes abrir esta fuente'];
		}
		$today = new DateTimeImmutable('today', new DateTimeZone($this->settings->timezone()));
		$schedule = Schedule::parse($bulletin->getSchedule());
		return [
			'id' => $bulletin->getId(),
			'name' => $bulletin->getName(),
			'role' => $role,
			'editorGroup' => $this->group($bulletin->getEditorGroup()),
			'sources' => $sources,
			'readers' => $role === 'editor' ? array_map($this->group(...), self::decode($bulletin->getReaders())) : [],
			'schedule' => $schedule->view(),
			'due' => $schedule->due($today),
		];
	}

	/**
	 * @param list<string> $groups
	 * @return list<IUser> the groups' active members, each once
	 */
	private function membersOf(array $groups): array {
		$members = [];
		foreach ($groups as $group) {
			foreach ($this->access->members($group) as $user) {
				$members[$user->getUID()] = $user;
			}
		}
		return array_values($members);
	}

	/** @return IaGroup */
	private function group(string $id): array {
		return ['id' => $id, 'name' => $this->groups->get($id)?->getDisplayName() ?? $id];
	}

	/** @return list<mixed> */
	private static function decode(string $json): array {
		return json_decode($json, true) ?: [];
	}
}
