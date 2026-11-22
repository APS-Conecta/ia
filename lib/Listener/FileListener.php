<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Listener;

use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Reading\Readings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deleting a file deletes its Reading (gestion-aio#162, Readings) and its Reading's Run; deleting
 * a folder, those of every file under it with a Reading, found before they go. A file deleted
 * while a Reading of it is being taken gets none: the Run finds it gone.
 *
 * @template-implements IEventListener<BeforeNodeDeletedEvent>
 */
class FileListener implements IEventListener {
	public function __construct(
		private Readings $readings,
		private RunMapper $runs,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeNodeDeletedEvent) {
			return;
		}
		$node = $event->getNode();
		try {
			$ids = $node instanceof Folder ? $this->readings->under($node->getId()) : [$node->getId()];
			$this->readings->forget($ids);
			$this->runs->forget(Run::READING, $ids);
		} catch (Throwable $e) {
			// Never in the way of a deletion; by class only, as a message may name the file.
			$this->logger->error('«IA» could not forget the Readings of a deleted node: {error}', ['error' => $e::class]);
		}
	}
}
