<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Controller;

use OCA\Ia\Access\Access;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Review\Reviews;
use OCA\Ia\Settings\Admin;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * «IA» over OCS, the API its own pages read (gestion-aio#162, API contract). Consumers send
 * `OCS-APIRequest: true`, which is also the CSRF protection, and get the OCS envelope back.
 * Every route is attribute-routed and rate-limited, and is either open to any signed-in person,
 * each action checking who may see what, or the admin page's own, for admins and the groups it
 * is delegated to (MetadataTest holds all of it).
 *
 * @psalm-import-type IaAdminSettings from ResponseDefinitions
 * @psalm-import-type IaGroup from ResponseDefinitions
 * @psalm-import-type IaOffHours from ResponseDefinitions
 * @psalm-import-type IaReview from ResponseDefinitions
 * @psalm-import-type IaReviewItem from ResponseDefinitions
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/** Reads: a ceiling against a runaway client, not a quota. */
	private const READ_LIMIT = 120;
	/** Reviews asked for: each one is work for the Model. */
	private const REVIEW_LIMIT = 30;
	/** Settings saved: a ceiling against a runaway client. */
	private const WRITE_LIMIT = 30;
	/** Groups the admin page's picker offers per search. */
	private const GROUPS_SHOWN = 20;
	private const PERIOD = 60;
	private const NOT_FOUND = 'No encontramos ese documento, o ya no puedes abrirlo.';

	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private Reviews $reviews,
		private Access $access,
		private Settings $settings,
		private IGroupManager $groups,
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

	/**
	 * The caller as «IA» sees them: the Editor groups they belong to, none for anyone who isn't an
	 * Editor
	 *
	 * @return DataResponse<Http::STATUS_OK, array{editorGroups: list<IaGroup>}, array{}>
	 *
	 * 200: The caller's Editor groups
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/me')]
	public function me(): DataResponse {
		return new DataResponse(['editorGroups' => $this->named($this->access->editorGroups($this->user()))]);
	}

	/**
	 * The «IA» admin page: the Editor groups, Off hours and the timezone they are read in
	 *
	 * @return DataResponse<Http::STATUS_OK, IaAdminSettings, array{}>
	 *
	 * 200: The settings
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/settings')]
	public function adminSettings(): DataResponse {
		return new DataResponse($this->adminView());
	}

	/**
	 * Save the Editor groups and Off hours
	 *
	 * @param list<string> $editorGroups The Editor groups' ids
	 * @param IaOffHours $offHours When Off hours start and end, as HH:MM in the server's timezone
	 * @return DataResponse<Http::STATUS_OK, IaAdminSettings, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The settings, saved
	 * 422: A group doesn't exist or an hour isn't one
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/settings')]
	public function saveAdminSettings(array $editorGroups, array $offHours): DataResponse {
		try {
			$this->settings->save($editorGroups, $offHours);
		} catch (Refused $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return new DataResponse($this->adminView());
	}

	/**
	 * The groups whose name or id holds the search, for the admin page's group picker
	 *
	 * @param string $search What the admin typed
	 * @return DataResponse<Http::STATUS_OK, array{groups: list<IaGroup>}, array{}>
	 *
	 * 200: Up to 20 groups
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/groups')]
	public function adminGroups(string $search = ''): DataResponse {
		$found = array_map(static fn ($group): string => $group->getGID(), $this->groups->search($search, self::GROUPS_SHOWN));
		return new DataResponse(['groups' => $this->named(array_values($found))]);
	}

	/** @return IaAdminSettings */
	private function adminView(): array {
		return [
			'editorGroups' => $this->named($this->settings->editorGroups()),
			'offHours' => $this->settings->offHours(),
			'timezone' => $this->settings->timezone(),
		];
	}

	/**
	 * @param list<string> $ids groups that exist
	 * @return list<IaGroup>
	 */
	private function named(array $ids): array {
		return array_map(fn (string $id): array => ['id' => $id, 'name' => $this->groups->get($id)?->getDisplayName() ?? $id], $ids);
	}

	/** The signed-in caller, whom #[NoAdminRequired] guarantees. */
	private function user(): string {
		return (string)$this->session->getUser()?->getUID();
	}
}
