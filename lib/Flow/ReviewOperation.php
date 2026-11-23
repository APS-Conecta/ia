<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Flow;

use OCA\Ia\Access\Access;
use OCA\Ia\Action\Actions;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Report;
use OCA\Ia\Db\ReportMapper;
use OCA\Ia\Refused;
use OCA\Ia\Review\Reviews;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\SystemTag\MapperEvent;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * The Flow operation «Revisar con IA» (APS-Conecta/ia#11), for admins who want Flow's richer
 * conditions. A file event a rule matches puts the automatic Review of each file «IA» reads there
 * (the file, or those in a folder) in line for Off hours, like an Automatic action, with the rule's
 * instructions. It acts as the person whose session set the event off; with no session it fails
 * into «Estado» and nobody is told (Reviews::source).
 *
 * One automatic Review per file: an Automatic action's folder wins over a Flow rule, and among
 * Flow rules the one with the lowest id, so the same file keeps the same Review.
 */
class ReviewOperation implements ISpecificOperation {
	/** Flow's file entity, by name: workflowengine's own class. */
	private const FILE_ENTITY = 'OCA\WorkflowEngine\Entity\File';

	public function __construct(
		private Access $access,
		private Reviews $reviews,
		private Actions $actions,
		private FlowRules $rules,
		private ReportMapper $reports,
		private IRootFolder $root,
		private IUserSession $session,
		private IURLGenerator $url,
		private LoggerInterface $logger,
	) {
	}

	public function getEntityId(): string {
		return self::FILE_ENTITY;
	}

	public function getDisplayName(): string {
		return 'Revisar con IA';
	}

	public function getDescription(): string {
		return 'Revisa con «IA», fuera de horario, cada archivo que cumpla la regla, con tus instrucciones.';
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app-dark.svg');
	}

	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}

	/** The rule's name and instructions, as an Automatic action's are checked. */
	public function validateOperation(string $name, array $checks, string $operation): void {
		$rule = FlowRules::parse($operation);
		try {
			Actions::name($rule['name'], 'la regla de Flujo');
			Actions::instructions($rule['instructions']);
		} catch (Refused $e) {
			throw new UnexpectedValueException($e->getMessage());
		}
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		try {
			$node = $this->node($eventName, $event);
			$matched = $node === null ? [] : $ruleMatcher->getFlows(false);
			if ($matched === []) {
				return;
			}
			$changer = $this->session->getUser()?->getUID();
			$lowest = min(array_map(static fn (array $rule): int => (int)$rule['id'], $matched));
			foreach ($this->access->files($node) as $file) {
				if ($this->actions->of($file->getId()) !== null) {
					continue;
				}
				// A lower Flow rule that holds the file's Review keeps it.
				$held = $this->reports->automaticOf($file->getId());
				$id = $held?->getOrigin() === Report::FLOW && (int)$held->getRule() < $lowest ? (int)$held->getRule() : $lowest;
				$rule = $this->rules->one($id) ?? $this->rules->one($lowest);
				if ($rule !== null) {
					$this->reviews->automatic($file, Report::FLOW, $rule['id'], $rule['instructions'], $changer);
				}
			}
		} catch (Throwable $e) {
			// Never in the way of the change; by class only, as a message may name the file.
			$this->logger->error('«IA» could not line up a Flow rule\'s Review: {error}', ['error' => $e::class]);
		}
	}

	/** The file or folder the event is about, as Flow's file entity finds it; null for a deletion or another kind. */
	private function node(string $eventName, Event $event): ?Node {
		if ($event instanceof MapperEvent) {
			return $event->getEvent() === MapperEvent::EVENT_ASSIGN && $event->getObjectType() === 'files'
				? $this->root->getFirstNodeById((int)$event->getObjectId())
				: null;
		}
		if (!$event instanceof GenericEvent || str_ends_with($eventName, 'postDelete')) {
			return null;
		}
		// A renamed or copied file's subject is [from, to].
		$subject = $event->getSubject();
		$node = is_array($subject) ? ($subject[1] ?? null) : $subject;
		return $node instanceof Node ? $node : null;
	}
}
