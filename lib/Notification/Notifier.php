<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Notification;

use OCA\Ia\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** «IA»'s notifications, in Spanish: «Revisión lista» to the person who asked for it, «Nueva edición» to a Bulletin's Readers. */
class Notifier implements INotifier {
	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'IA';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$id = $notification->getObjectId();
		$parameters = $notification->getSubjectParameters();
		$name = (string)($parameters['name'] ?? '');
		[$subject, $hash] = match ($notification->getApp() === Application::APP_ID ? $notification->getSubject() : null) {
			'review_ready' => ['Revisión lista', "#revisiones/$id"],
			'edition_published' => ['Nueva edición', '#boletines/' . (int)($parameters['bulletin'] ?? 0) . "/$id"],
			default => throw new UnknownNotificationException(),
		};
		return $notification
			->setRichSubject("$subject: {name}", ['name' => ['type' => 'highlight', 'id' => $id, 'name' => $name]])
			->setParsedSubject("$subject: $name")
			->setLink($this->url->linkToRouteAbsolute('ia.page.index') . $hash)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
