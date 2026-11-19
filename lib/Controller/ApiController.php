<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Controller;

use OCA\Ia\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * «IA» over OCS, the API its own pages read (gestion-aio#162, API contract). Consumers send
 * `OCS-APIRequest: true`, which is also the CSRF protection, and get the OCS envelope back.
 * Every route is attribute-routed, open to any signed-in person and rate-limited
 * (MetadataTest holds all three); who may see what is checked inside each action.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/** Reads: a ceiling against a runaway client, not a quota. */
	private const READ_LIMIT = 120;
	private const PERIOD = 60;

	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * «Para ti», the caller's start page: the Bulletins they read
	 *
	 * No Bulletin exists yet, so every person reads none and gets the empty state.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{bulletins: list<empty>}, array{}>
	 *
	 * 200: The caller's «Para ti»
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/para-ti')]
	public function paraTi(): DataResponse {
		return new DataResponse(['bulletins' => []]);
	}
}
