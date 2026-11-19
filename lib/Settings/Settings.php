<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Settings;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Refused;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;

/**
 * What the admin sets on the «IA» page (gestion-aio#162, Limits): the Editor groups and Off
 * hours, read from the app config on every call, so a change counts at once.
 */
class Settings {
	/** Off hours until an admin changes them: 22:00 to 06:00, in the server's timezone. */
	private const OFF_HOURS = ['start' => '22:00', 'end' => '06:00'];

	private const GROUPS = 'editor_groups';
	private const HOURS = 'off_hours';
	private const HH_MM = '/^([01]\d|2[0-3]):[0-5]\d$/';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IGroupManager $groups,
	) {
	}

	/** @return list<string> the Editor groups' ids, of groups that still exist */
	public function editorGroups(): array {
		return array_values(array_filter($this->appConfig->getValueArray(Application::APP_ID, self::GROUPS), $this->groups->groupExists(...)));
	}

	/** @return array{start: string, end: string} */
	public function offHours(): array {
		return $this->appConfig->getValueArray(Application::APP_ID, self::HOURS) + self::OFF_HOURS;
	}

	/** The timezone Off hours are read in: the server's, which the suite sets to the centre's. */
	public function timezone(): string {
		return $this->config->getSystemValueString('default_timezone', 'UTC') ?: 'UTC';
	}

	/**
	 * Saves the page as the request sends it, checked here alone.
	 *
	 * @param array<mixed> $editorGroups
	 * @param array<mixed> $offHours
	 * @throws Refused when a group doesn't exist or an hour isn't one
	 */
	public function save(array $editorGroups, array $offHours): void {
		foreach ($editorGroups as $group) {
			if (!is_string($group) || !$this->groups->groupExists($group)) {
				throw new Refused('No existe el grupo «' . (is_string($group) ? $group : json_encode($group)) . '».');
			}
		}
		$start = $offHours['start'] ?? null;
		$end = $offHours['end'] ?? null;
		if (!is_string($start) || !is_string($end) || preg_match(self::HH_MM, $start) !== 1 || preg_match(self::HH_MM, $end) !== 1) {
			throw new Refused('Escribe las horas como HH:MM, de 00:00 a 23:59.');
		}
		if ($start === $end) {
			throw new Refused('El fuera de horario debe empezar y terminar a horas distintas.');
		}
		$this->appConfig->setValueArray(Application::APP_ID, self::GROUPS, array_values(array_unique($editorGroups)));
		$this->appConfig->setValueArray(Application::APP_ID, self::HOURS, ['start' => $start, 'end' => $end]);
	}
}
