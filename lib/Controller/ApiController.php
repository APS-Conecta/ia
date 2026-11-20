<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Controller;

use OCA\Ia\Access\Access;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Bulletin\Bulletins;
use OCA\Ia\Bulletin\Editions;
use OCA\Ia\Forbidden;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Review\Reviews;
use OCA\Ia\Settings\Admin;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\DoesNotExistException;
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
 * @psalm-import-type IaBulletin from ResponseDefinitions
 * @psalm-import-type IaBulletinItem from ResponseDefinitions
 * @psalm-import-type IaEdition from ResponseDefinitions
 * @psalm-import-type IaEditionItem from ResponseDefinitions
 * @psalm-import-type IaReadersPreview from ResponseDefinitions
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
		private Bulletins $bulletins,
		private Editions $editions,
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
	 * A short document is reviewed now, a longer one in Off hours; the caller is notified when the
	 * report is ready. A document the caller already has a Review of answers that Review, asked
	 * again when it failed or the document changed.
	 *
	 * @param int $fileId The document's file id
	 * @return DataResponse<Http::STATUS_CREATED|Http::STATUS_OK, array{review: IaReview, start: 'now'|'offHours'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: A new Review, under way or waiting for Off hours
	 * 200: The caller's Review of this document
	 * 404: The caller can't open the document
	 * 422: «IA» can't read this document: its format, a scan, or a damaged or protected file
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::REVIEW_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews')]
	public function createReview(int $fileId): DataResponse {
		try {
			['created' => $created] = $answer = $this->reviews->create($this->user(), $fileId);
			return new DataResponse(['review' => $answer['review'], 'start' => $answer['start']], $created ? Http::STATUS_CREATED : Http::STATUS_OK);
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
	 * @return DataResponse<Http::STATUS_OK, array{review: IaReview, start: 'now'|'offHours'}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The Review, under way again or waiting for Off hours
	 * 404: No such Review of the caller's, or they can't open its document any more
	 * 422: The Review is still under way, or «IA» can't read the document: its format, a scan, or a damaged or protected file
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::REVIEW_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/reviews/{id}/again')]
	public function reviewAgain(int $id): DataResponse {
		try {
			return new DataResponse($this->reviews->again($this->user(), $id));
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
		return new DataResponse(['groups' => $this->found($search)]);
	}

	/**
	 * «Boletines»: the Bulletins the caller edits or reads, by name
	 *
	 * @return DataResponse<Http::STATUS_OK, array{bulletins: list<IaBulletinItem>}, array{}>
	 *
	 * 200: The caller's Bulletins
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/bulletins')]
	public function bulletins(): DataResponse {
		return new DataResponse(['bulletins' => $this->bulletins->all($this->user())]);
	}

	/**
	 * Create a Bulletin for one of the caller's Editor groups
	 *
	 * @param string $name The Bulletin's name
	 * @param string $editorGroup The Editor group that owns it, one of the caller's
	 * @param list<array{type: string, id: string}> $sources Folders, documents and calendars the whole Editor group can open
	 * @param list<string> $readers The Reader groups' ids
	 * @param array{kind: string, day?: ?int} $schedule When its Editions are due
	 * @return DataResponse<Http::STATUS_CREATED, array{bulletin: IaBulletin}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: The Bulletin
	 * 403: The Editor group isn't one of the caller's
	 * 422: A field isn't one, or a member of the Editor group can't open a Source
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bulletins')]
	public function createBulletin(string $name, string $editorGroup, array $sources, array $readers, array $schedule): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse([
			'bulletin' => $this->bulletins->create($this->user(), ['name' => $name, 'editorGroup' => $editorGroup, 'sources' => $sources, 'readers' => $readers, 'schedule' => $schedule]),
		], Http::STATUS_CREATED));
	}

	/**
	 * Who a Bulletin with these Sources and Reader groups reaches, for an Editor setting one up
	 *
	 * @param list<array{type: string, id: string}> $sources The Sources
	 * @param list<string> $readers The Reader groups' ids
	 * @return DataResponse<Http::STATUS_OK, IaReadersPreview, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: How many Readers it reaches, and who is left out because they can't open a Source
	 * 403: The caller is no Editor
	 * 422: A field isn't one
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/bulletins/preview')]
	public function previewBulletin(array $sources, array $readers): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse($this->bulletins->preview($this->user(), ['sources' => $sources, 'readers' => $readers])));
	}

	/**
	 * One Bulletin the caller edits or reads, with when its next Edition is due
	 *
	 * @param int $id The Bulletin's id
	 * @return DataResponse<Http::STATUS_OK, array{bulletin: IaBulletin}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The Bulletin
	 * 403: The caller neither edits nor reads it
	 * 404: No such Bulletin
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/bulletins/{id}')]
	public function bulletin(int $id): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['bulletin' => $this->bulletins->one($this->user(), $id)]));
	}

	/**
	 * Change a Bulletin of the caller's Editor group: its name, Sources, Readers and schedule
	 *
	 * @param int $id The Bulletin's id
	 * @param string $name The Bulletin's name
	 * @param list<array{type: string, id: string}> $sources Folders, documents and calendars the whole Editor group can open
	 * @param list<string> $readers The Reader groups' ids
	 * @param array{kind: string, day?: ?int} $schedule When its Editions are due
	 * @return DataResponse<Http::STATUS_OK, array{bulletin: IaBulletin}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The Bulletin, changed
	 * 403: The caller isn't in its Editor group
	 * 404: No such Bulletin
	 * 422: A field isn't one, or a member of the Editor group can't open a Source
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/bulletins/{id}')]
	public function changeBulletin(int $id, string $name, array $sources, array $readers, array $schedule): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse([
			'bulletin' => $this->bulletins->change($this->user(), $id, ['name' => $name, 'sources' => $sources, 'readers' => $readers, 'schedule' => $schedule]),
		]));
	}

	/**
	 * Delete a Bulletin of the caller's Editor group
	 *
	 * @param int $id The Bulletin's id
	 * @return DataResponse<Http::STATUS_OK, array{}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Deleted
	 * 403: The caller isn't in its Editor group
	 * 404: No such Bulletin
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/bulletins/{id}')]
	public function deleteBulletin(int $id): DataResponse {
		return $this->refusable(function () use ($id): DataResponse {
			$this->bulletins->delete($this->user(), $id);
			return new DataResponse([]);
		});
	}

	/**
	 * A Bulletin's published Editions that the caller can see: those whose every file still there
	 * they can open
	 *
	 * @param int $id The Bulletin's id
	 * @return DataResponse<Http::STATUS_OK, array{editions: list<IaEditionItem>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Its Editions, newest first
	 * 403: The caller neither edits nor reads it
	 * 404: No such Bulletin
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/bulletins/{id}/editions')]
	public function editions(int $id): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['editions' => $this->editions->all($this->user(), $id)]));
	}

	/**
	 * An Edition: its Resumen, written by AI ('' when no document could be read), and its sections,
	 * each document with its Reading
	 *
	 * @param int $id The Edition's id
	 * @return DataResponse<Http::STATUS_OK, array{edition: IaEdition}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The Edition
	 * 404: No such Edition, it isn't published yet, or the caller can't see it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/editions/{id}')]
	public function edition(int $id): DataResponse {
		try {
			return new DataResponse(['edition' => $this->editions->one($this->user(), $id)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No encontramos esa edición, o ya no puedes leerla.'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The caller's calendars, theirs and those shared with them, to pick a Bulletin's Sources from
	 *
	 * @return DataResponse<Http::STATUS_OK, array{calendars: list<array{id: string, name: string}>}, array{}>
	 *
	 * 200: The calendars
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/calendars')]
	public function calendars(): DataResponse {
		$calendars = array_map(static fn ($calendar): array => ['id' => $calendar->getKey(), 'name' => Access::calendarName($calendar)],
			$this->access->calendarsOf($this->user()));
		return new DataResponse(['calendars' => $calendars]);
	}

	/**
	 * The groups whose name or id holds the search, for an Editor picking a Bulletin's Readers
	 *
	 * @param string $search What the Editor typed
	 * @return DataResponse<Http::STATUS_OK, array{groups: list<IaGroup>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Up to 20 groups
	 * 403: The caller is no Editor
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/groups')]
	public function groups(string $search = ''): DataResponse {
		if ($this->access->editorGroups($this->user()) === []) {
			return new DataResponse(['message' => 'Solo los editores eligen lectores.'], Http::STATUS_FORBIDDEN);
		}
		return new DataResponse(['groups' => $this->found($search)]);
	}

	/** @return list<IaGroup> up to GROUPS_SHOWN groups whose name or id holds $search */
	private function found(string $search): array {
		return $this->named(array_values(array_map(static fn ($group): string => $group->getGID(), $this->groups->search($search, self::GROUPS_SHOWN))));
	}

	/**
	 * $answer's response, or the Spanish reason it was refused: 403, 404 or 422.
	 *
	 * @param callable(): DataResponse $answer
	 */
	private function refusable(callable $answer): DataResponse {
		try {
			return $answer();
		} catch (Forbidden $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'No encontramos ese boletín.'], Http::STATUS_NOT_FOUND);
		} catch (Refused $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
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
