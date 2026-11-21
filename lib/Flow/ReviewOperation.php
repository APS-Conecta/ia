<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Flow;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Report;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Review\Reviews;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\GenericEvent;
use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Flow operation «Revisar con IA» (APS-Conecta/ia#11), for admins who want Flow's richer
 * conditions. A file event a rule matches puts the file's automatic Review in line for Off hours,
 * like an Automatic action, with the rule's instructions. It acts as the person whose session set
 * the event off; with no session it fails into «Estado» and nobody is told (Reviews::source).
 */
class ReviewOperation implements ISpecificOperation {
	/** Flow's file entity, by name: workflowengine's own class. */
	private const FILE_ENTITY = 'OCA\WorkflowEngine\Entity\File';

	public function __construct(
		private Reviews $reviews,
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

	public function validateOperation(string $name, array $checks, string $operation): void {
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		// A renamed or copied file's subject is [from, to].
		$subject = $event instanceof GenericEvent ? $event->getSubject() : null;
		$file = is_array($subject) ? ($subject[1] ?? null) : $subject;
		if (!$file instanceof File || !TextReader::reads($file->getName()) || str_ends_with($eventName, 'postDelete')) {
			return;
		}
		try {
			foreach ($ruleMatcher->getFlows(false) as $rule) {
				$this->reviews->automatic($file, Report::FLOW, (int)$rule['id'], (string)$rule['operation'], $this->session->getUser()?->getUID());
			}
		} catch (Throwable $e) {
			// Never in the way of the change; by class only, as a message may name the file.
			$this->logger->error('«IA» could not line up a Flow rule\'s Review: {error}', ['error' => $e::class]);
		}
	}
}
