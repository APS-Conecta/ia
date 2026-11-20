<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Notification;

use OCA\Ia\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * «IA»'s notifications, in Spanish: «Revisión lista» to whoever asked for it, «Nueva edición» to a
 * Bulletin's Readers, «Edición atrasada» and a Source that can't be read or is gone to its Editors.
 */
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
		$bulletin = '#boletines/' . (int)($parameters['bulletin'] ?? 0);
		[$subject, $hash] = match ($notification->getApp() === Application::APP_ID ? $notification->getSubject() : null) {
			'review_ready' => ['Revisión lista: {name}', "#revisiones/$id"],
			'edition_published' => ['Nueva edición: {name}', "$bulletin/$id"],
			'edition_late' => ['Edición atrasada: {name}', $bulletin],
			'source_unreadable' => ['No se pudo leer {source}, fuente de {name}', $bulletin],
			'source_gone' => ['Una fuente de {name} ya no está', $bulletin],
			default => throw new UnknownNotificationException(),
		};
		$rich = [];
		$parsed = $subject;
		foreach (['name', 'source'] as $key) {
			if (str_contains($subject, '{' . $key . '}')) {
				$value = (string)($parameters[$key] ?? '');
				$rich[$key] = ['type' => 'highlight', 'id' => "$key-$id", 'name' => $value];
				$parsed = str_replace('{' . $key . '}', $key === 'source' ? "«{$value}»" : $value, $parsed);
			}
		}
		return $notification
			->setRichSubject($subject, $rich)
			->setParsedSubject($parsed)
			->setLink($this->url->linkToRouteAbsolute('ia.page.index') . $hash)
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
	}
}
