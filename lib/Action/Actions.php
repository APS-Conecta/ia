<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Action;

use OCA\Ia\Access\Access;
use OCA\Ia\Db\Action;
use OCA\Ia\Db\ActionMapper;
use OCA\Ia\Db\Places;
use OCA\Ia\Forbidden;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Automatic actions (CONTEXT.md; gestion-aio#162, AutomaticActions): an Editor group puts a rule
 * on a folder, with optional instructions, and every file added to it, changed in it, moved or
 * copied into it gets an automatic Review in Off hours (Reviews::automatic).
 *
 * - **Only the Editor group's members** list, create, change and delete its actions.
 * - **A folder the whole Editor group can open**: one a member can't open is refused, naming who.
 * - **One action per folder**; when folders with actions nest, the innermost one's wins.
 *
 * @psalm-import-type IaAction from ResponseDefinitions
 */
class Actions {
	/** Instructions longer than this would crowd the document out of the Model's context. */
	public const MAX_INSTRUCTIONS = 1000;
	private const NOT_THEIRS = 'Solo los miembros del grupo editor de una acción automática pueden verla o cambiarla.';

	/** @var list<Action>|null this request's actions, read once: a folder copy fires an event per file */
	private ?array $known = null;

	public function __construct(
		private ActionMapper $actions,
		private Access $access,
		private IGroupManager $groups,
		private Places $places,
	) {
	}

	/**
	 * @return list<IaAction> the actions of $userId's Editor groups, by name
	 * @throws Forbidden when they are no Editor
	 */
	public function all(string $userId): array {
		$groups = $this->editorGroups($userId);
		$mine = array_filter($this->actions->all(), static fn (Action $action): bool => in_array($action->getEditorGroup(), $groups, true));
		return array_values(array_map(fn (Action $action): array => $this->view($userId, $action), $mine));
	}

	/**
	 * @param array<mixed> $input {name, editorGroup, folder, instructions}
	 * @return IaAction
	 * @throws Forbidden when $userId isn't in that Editor group
	 * @throws Refused
	 */
	public function create(string $userId, array $input): array {
		$group = $input['editorGroup'] ?? null;
		if (!is_string($group) || !in_array($group, $this->editorGroups($userId), true)) {
			throw new Forbidden('Solo puedes crear acciones automáticas de tus grupos editores.');
		}
		$action = new Action();
		$action->setEditorGroup($group);
		$this->fill($userId, $action, $input);
		return $this->view($userId, $this->actions->insert($action));
	}

	/**
	 * @param array<mixed> $input {name, folder, instructions}
	 * @return IaAction
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 * @throws Refused
	 */
	public function change(string $userId, int $id, array $input): array {
		$action = $this->edited($userId, $id);
		$this->fill($userId, $action, $input);
		return $this->view($userId, $this->actions->update($action));
	}

	/**
	 * Deletes an action: no new Review comes from it; its reports stay until report retention.
	 *
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 */
	public function delete(string $userId, int $id): void {
		$this->actions->delete($this->edited($userId, $id));
	}

	public function byId(int $id): ?Action {
		return $this->actions->byId($id);
	}

	/**
	 * Who acts on the file (or folder) $nodeId for the action: an automatic Review when whoever
	 * changed the file can't open it, a Reading's Run, the night's sweep of its folder. The first
	 * active member, by id, of the action's Editor group who can open it; null when none can.
	 *
	 * @param 'file'|'folder' $type
	 */
	public function actor(Action $action, int $nodeId, string $type = 'file'): ?string {
		$members = $this->access->members($action->getEditorGroup());
		usort($members, static fn (IUser $a, IUser $b): int => strcmp($a->getUID(), $b->getUID()));
		foreach ($members as $member) {
			if ($this->access->node($member->getUID(), $nodeId, $type) !== null) {
				return $member->getUID();
			}
		}
		return null;
	}

	/** The action whose folder holds the file or folder $nodeId, or is it, at any depth: the innermost one; null when none. */
	public function of(int $nodeId): ?Action {
		$this->known ??= $this->actions->all();
		if ($this->known === []) {
			return null;
		}
		$places = $this->places->of([$nodeId, ...array_map(static fn (Action $action): int => $action->getFolder(), $this->known)]);
		$file = $places[$nodeId] ?? null;
		$found = null;
		foreach ($file === null ? [] : $this->known as $action) {
			$folder = $places[$action->getFolder()] ?? null;
			if ($folder !== null && ($file === $folder || Places::within($file, $folder))
				&& ($found === null || strlen($folder['path']) > strlen($places[$found->getFolder()]['path']))) {
				$found = $action;
			}
		}
		return $found;
	}

	/** @return list<string> $userId's Editor groups @throws Forbidden when none */
	private function editorGroups(string $userId): array {
		return $this->access->editorGroups($userId) ?: throw new Forbidden('Solo los editores tienen acciones automáticas.');
	}

	/**
	 * @throws DoesNotExistException
	 * @throws Forbidden
	 */
	private function edited(string $userId, int $id): Action {
		$action = $this->actions->byId($id) ?? throw new DoesNotExistException('No such Automatic action');
		if (!in_array($action->getEditorGroup(), $this->access->editorGroups($userId), true)) {
			throw new Forbidden(self::NOT_THEIRS);
		}
		return $action;
	}

	/**
	 * Checks $input and sets the action's name, folder and instructions from it.
	 *
	 * @param array<mixed> $input
	 * @throws Refused
	 */
	private function fill(string $userId, Action $action, array $input): void {
		$name = self::name($input['name'] ?? '', 'la acción automática');
		$id = $input['folder'] ?? null;
		$folder = (is_string($id) || is_int($id)) && ctype_digit((string)$id) ? $this->access->node($userId, (int)$id, 'folder') : null;
		if ($folder === null) {
			throw new Refused('Elige una carpeta que puedas abrir.');
		}
		foreach ($this->actions->all() as $other) {
			if ($other->getFolder() === (int)$id && $other->getId() !== $action->getId()) {
				throw new Refused('Esa carpeta ya tiene una acción automática.');
			}
		}
		$cannot = $this->access->lockedOut($action->getEditorGroup(), 'folder', (string)$id);
		if ($cannot !== []) {
			throw new Refused('Todo el grupo editor debe poder abrir la carpeta, y «' . $folder->getName() . '» no la puede abrir: ' . implode(', ', $cannot) . '.');
		}
		$instructions = self::instructions($input['instructions'] ?? '');
		$action->setName($name);
		$action->setFolder((int)$id);
		$action->setInstructions($instructions === '' ? null : $instructions);
	}

	/**
	 * The name of an Automatic action or a Flow rule ($of names which), trimmed.
	 *
	 * @throws Refused when it is empty or too long
	 */
	public static function name(mixed $name, string $of): string {
		$name = trim(is_string($name) ? $name : '');
		if ($name === '' || mb_strlen($name) > 200) {
			throw new Refused("Escribe un nombre para $of, de hasta 200 caracteres.");
		}
		return $name;
	}

	/**
	 * The instructions of an Automatic action or a Flow rule, trimmed: what else its Reviews check.
	 *
	 * @throws Refused when they are too long
	 */
	public static function instructions(mixed $instructions): string {
		$instructions = trim(is_string($instructions) ? $instructions : '');
		if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
			throw new Refused('Las instrucciones pueden tener hasta ' . self::MAX_INSTRUCTIONS . ' caracteres.');
		}
		return $instructions;
	}

	/** @return IaAction */
	private function view(string $userId, Action $action): array {
		$folder = $this->access->node($userId, $action->getFolder(), 'folder');
		return [
			'id' => $action->getId(),
			'name' => $action->getName(),
			'editorGroup' => ['id' => $action->getEditorGroup(), 'name' => $this->groups->get($action->getEditorGroup())?->getDisplayName() ?? $action->getEditorGroup()],
			'folder' => ['id' => (string)$action->getFolder(), 'name' => $folder?->getName() ?? 'Ya no puedes abrir esta carpeta'],
			'instructions' => (string)$action->getInstructions(),
		];
	}
}
