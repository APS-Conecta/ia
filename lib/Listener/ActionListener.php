<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Ia\Action\Actions;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Review\Reviews;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A file created, written, moved or copied under an Automatic action's folder (a folder brings its
 * files) puts its automatic Review in line, for whoever did it (gestion-aio#162,
 * AutomaticActions). Files «IA» doesn't read are left alone.
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
			foreach ($node === null ? [] : self::files($node) as $file) {
				$action = $this->actions->of($file->getId());
				if ($action !== null) {
					$this->reviews->automatic($file, $action, $this->session->getUser()?->getUID());
				}
			}
		} catch (Throwable $e) {
			// Never in the way of the change; by class only, as a message may name the file.
			$this->logger->error('«IA» could not line up an automatic Review: {error}', ['error' => $e::class]);
		}
	}

	/** @return list<File> the files «IA» reads at $node: itself, or those under it at any depth */
	private static function files(Node $node): array {
		if ($node instanceof File) {
			return TextReader::reads($node->getName()) ? [$node] : [];
		}
		$found = [];
		foreach ($node instanceof Folder ? $node->getDirectoryListing() : [] as $child) {
			array_push($found, ...self::files($child));
		}
		return $found;
	}
}
