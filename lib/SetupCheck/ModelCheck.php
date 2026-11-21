<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\SetupCheck;

use OCA\Ia\Model\Health;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * The «IA» line of Nextcloud's setup checks (gestion-aio#162): whether a provider takes
 * `core:text2text`, so that a nightly connector update that drops it shows up, and whether the
 * Model answers, with a warning once the model-down alert has gone out.
 */
class ModelCheck implements ISetupCheck {
	public function __construct(
		private Health $health,
	) {
	}

	public function getCategory(): string {
		return 'ai';
	}

	public function getName(): string {
		return 'Modelo de «IA»';
	}

	public function run(): SetupResult {
		$health = $this->health->status();
		if (!$health['provider']) {
			return SetupResult::error('Ningún proveedor responde core:text2text, así que «IA» no puede pedir nada al modelo. Revisa el conector en Administración › Inteligencia artificial.');
		}
		if ($health['alerted']) {
			return SetupResult::warning('El modelo no responde desde hace más de ' . intdiv(Health::ALERT, 60) . ' minutos. «IA» retoma su trabajo cuando vuelva.');
		}
		return match ($health['state']) {
			'up' => SetupResult::success('El modelo responde.'),
			'down' => SetupResult::info('El modelo no respondió en la última comprobación.'),
			'unknown' => SetupResult::info('«IA» todavía no comprueba si el modelo responde.'),
		};
	}
}
