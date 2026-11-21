<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Ia\Action\Actions;
use OCA\Ia\Db\Report;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Review\Reviews;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A file created, written, moved or copied under an Automatic action's folder (a folder brings its
 * files) puts its automatic Review in line, for whoever did it (gestion-aio#162,
 * AutomaticActions); a rename that stays under the same action doesn't. Files «IA» doesn't read
 * are left alone.
 *
 * @template-implements IEventListener<NodeCreatedEvent|NodeWrittenEvent|NodeRenamedEvent|NodeCopiedEvent>
 */
class ActionListener implements IEventListener {
	public function __construct(
		private Actions $actions,
		private Reviews $reviews,
		private IUserSession $session,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$node = match (true) {
			$event instanceof NodeCreatedEvent, $event instanceof NodeWrittenEvent => $event->getNode(),
			$event instanceof NodeRenamedEvent, $event instanceof NodeCopiedEvent => $event->getTarget(),
			default => null,
		};
		try {
			// A rename within one action's folder (the folder's own, too) brings nothing new to it.
			$before = $event instanceof NodeRenamedEvent ? $this->actions->of(self::parentId($event->getSource())) : null;
			foreach ($node === null ? [] : TextReader::files($node) as $file) {
				$action = $this->actions->of($file->getId());
				if ($action !== null && $action->getId() !== $before?->getId()) {
					$this->reviews->automatic($file, Report::ACTION, $action->getId(), (string)$action->getInstructions(), $this->session->getUser()?->getUID());
				}
			}
		} catch (Throwable $e) {
			// Never in the way of the change; by class only, as a message may name the file.
			$this->logger->error('«IA» could not line up an automatic Review: {error}', ['error' => $e::class]);
		}
	}

	/** The id of the folder $node was in; 0 when that is gone too. */
	private static function parentId(Node $node): int {
		try {
			return $node->getParent()->getId();
		} catch (NotFoundException) {
			return 0;
		}
	}
}
