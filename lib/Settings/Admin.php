<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Settings;

use OCA\Ia\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * The «IA» admin page: the Editor groups and Off hours. An admin can delegate it to a group (for
 * example a head of unit) under Administration privileges; the routes it calls carry
 * #[AuthorizedAdminSetting] for this class.
 */
class Admin implements IDelegatedSettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	public function getName(): ?string {
		return 'Grupos editores y fuera de horario';
	}

	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
