<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Dashboard;

use DateTimeImmutable;
use IntlDateFormatter;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Bulletin\Editions;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

/** The «Boletines» Dashboard widget (gestion-aio#162, Dashboard): the latest Editions the person can see. */
class BulletinsWidget implements IAPIWidgetV2, IIconWidget {
	public function __construct(
		private Editions $editions,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return 'ia-boletines';
	}

	public function getTitle(): string {
		return 'Boletines';
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return '';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('ia.page.index') . '#boletines';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$dates = IntlDateFormatter::create('es_CL', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC');
		$items = array_map(fn (array $edition): WidgetItem => new WidgetItem(
			$edition['bulletin']['name'],
			'Edición del ' . $dates->format(new DateTimeImmutable($edition['date'] . ' 12:00', new \DateTimeZone('UTC'))),
			$this->url->linkToRouteAbsolute('ia.page.index') . '#boletines/' . $edition['bulletin']['id'] . '/' . $edition['id'],
			$this->getIconUrl(),
			(string)$edition['id'],
		), array_slice($this->editions->latest($userId), 0, $limit));
		return new WidgetItems($items, 'Todavía no hay ediciones para ti.');
	}
}
