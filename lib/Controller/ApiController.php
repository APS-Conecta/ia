<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Controller;

use OCA\Ia\AppInfo\Application;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Review\Reviews;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * «IA» over OCS, the API its own pages read (gestion-aio#162, API contract). Consumers send
 * `OCS-APIRequest: true`, which is also the CSRF protection, and get the OCS envelope back.
 * Every route is attribute-routed, open to any signed-in person and rate-limited
 * (MetadataTest holds all three); who may see what is checked inside each action.
 *
 * @psalm-import-type IaReview from ResponseDefinitions
 * @psalm-import-type IaReviewItem from ResponseDefinitions
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/** Reads: a ceiling against a runaway client, not a quota. */
	private const READ_LIMIT = 120;
	/** Reviews asked for: each one is work for the Model. */
	private const REVIEW_LIMIT = 30;
	private const PERIOD = 60;
	private const NOT_FOUND = 'No encontramos ese documento, o ya no puedes abrirlo.';

	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private Reviews $reviews,
	) {
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

	/**
	 * «Revisiones»: the caller's Reviews of files they can still open, newest first
	 *
	 * @return DataResponse<Http::STATUS_OK, array{reviews: list<IaReviewItem>}, array{}>
	 *
	 * 200: The caller's Reviews
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reviews')]
	public function reviews(): DataResponse {
		return new DataResponse(['reviews' => $this->reviews->all($this->user())]);
	}

	/**
	 * Review a document the caller can open
	 *
	 * A short document is reviewed now; the caller is notified when the report is ready.
	 *
	 * @param int $fileId The document's file id
	 * @return DataResponse<Http::STATUS_CREATED, array{review: IaReview, start: 'now'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: The Review, under way
	 * 404: The caller can't open the document
	 * 422: «IA» can't review this document yet
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::REVIEW_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews')]
	public function createReview(int $fileId): DataResponse {
		try {
			return new DataResponse(['review' => $this->reviews->create($this->user(), $fileId), 'start' => 'now'], Http::STATUS_CREATED);
		} catch (NotFoundException) {
			return new DataResponse(['message' => self::NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (Refused $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * One of the caller's Reviews, with its report once it is ready
	 *
	 * @param int $id The Review's id
	 * @return DataResponse<Http::STATUS_OK, array{review: IaReview}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The Review
	 * 404: No such Review of the caller's, or they can't open its document any more
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reviews/{id}')]
	public function review(int $id): DataResponse {
		try {
			return new DataResponse(['review' => $this->reviews->one($this->user(), $id)]);
		} catch (NotFoundException) {
			return new DataResponse(['message' => self::NOT_FOUND], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Review again the current version of a Review's document
	 *
	 * @param int $id The Review's id
	 * @return DataResponse<Http::STATUS_OK, array{review: IaReview, start: 'now'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The Review, under way again
	 * 404: No such Review of the caller's, or they can't open its document any more
	 * 422: The Review is still under way, or «IA» can't review the document yet
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::REVIEW_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews/{id}/again')]
	public function reviewAgain(int $id): DataResponse {
		try {
			return new DataResponse(['review' => $this->reviews->again($this->user(), $id), 'start' => 'now']);
		} catch (NotFoundException) {
			return new DataResponse(['message' => self::NOT_FOUND], Http::STATUS_NOT_FOUND);
		} catch (Refused $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** The signed-in caller, whom #[NoAdminRequired] guarantees. */
	private function user(): string {
		return (string)$this->session->getUser()?->getUID();
	}
}
