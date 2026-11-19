<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Notification;

use OCA\Ia\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** «IA»'s notifications, in Spanish: for now, «Revisión lista» to the person who asked for it. */
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
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'review_ready') {
			throw new UnknownNotificationException();
		}
		$id = $notification->getObjectId();
		$name = (string)($notification->getSubjectParameters()['name'] ?? '');
		return $notification
			->setRichSubject('Revisión lista: {document}', ['document' => ['type' => 'highlight', 'id' => $id, 'name' => $name]])
			->setParsedSubject("Revisión lista: $name")
			->setLink($this->url->linkToRouteAbsolute('ia.page.index') . '#revisiones/' . $id)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
