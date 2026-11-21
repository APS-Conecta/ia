<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Controller;

use OCA\Ia\Access\Access;
use OCA\Ia\Action\Actions;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Bulletin\Bulletins;
use OCA\Ia\Bulletin\Editions;
use OCA\Ia\Bulletin\ParaTi;
use OCA\Ia\Flow\FlowRules;
use OCA\Ia\Forbidden;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Review\Reviews;
use OCA\Ia\Run\Estado;
use OCA\Ia\Settings\Admin;
use OCA\Ia\Settings\Settings;
use OCA\Ia\Withdrawn;
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
 * @psalm-import-type IaAction from ResponseDefinitions
 * @psalm-import-type IaFlowRule from ResponseDefinitions
 * @psalm-import-type IaAdminSettings from ResponseDefinitions
 * @psalm-import-type IaBulletin from ResponseDefinitions
 * @psalm-import-type IaBulletinItem from ResponseDefinitions
 * @psalm-import-type IaEdition from ResponseDefinitions
 * @psalm-import-type IaEditionItem from ResponseDefinitions
 * @psalm-import-type IaReadersPreview from ResponseDefinitions
 * @psalm-import-type IaGroup from ResponseDefinitions
 * @psalm-import-type IaOffHours from ResponseDefinitions
 * @psalm-import-type IaParaTi from ResponseDefinitions
 * @psalm-import-type IaReview from ResponseDefinitions
 * @psalm-import-type IaReviewItem from ResponseDefinitions
 * @psalm-import-type IaEstado from ResponseDefinitions
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
	private const NO_BULLETIN = 'No encontramos ese boletín.';
	private const NO_ACTION = 'No encontramos esa acción automática.';
	private const NO_EDITION = 'No encontramos esa edición, o ya no puedes leerla.';
	private const NO_RUN = 'No encontramos ese trabajo.';

	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private Reviews $reviews,
		private Access $access,
		private Settings $settings,
		private IGroupManager $groups,
		private Bulletins $bulletins,
		private Editions $editions,
		private Actions $actions,
		private ParaTi $paraTi,
		private Estado $estado,
		private FlowRules $flowRules,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * «Para ti», the caller's start page: the Bulletins they read, the latest Edition of each, and
	 * those Editions' Personal lists and protocols about to expire, merged
	 *
	 * @return DataResponse<Http::STATUS_OK, IaParaTi, array{}>
	 *
	 * 200: The caller's «Para ti»; empty lists when they read no Bulletin
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/para-ti')]
	public function paraTi(): DataResponse {
		return new DataResponse($this->paraTi->of($this->user()));
	}

	/**
	 * «Revisiones»: the caller's own Reviews and the automatic ones, of files they can still open, newest first
	 *
	 * @return DataResponse<Http::STATUS_OK, array{reviews: list<IaReviewItem>}, array{}>
	 *
	 * 200: The Reviews
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
	 * One of the caller's Reviews, or an automatic one of a file they can open, with its report once it is ready
	 *
	 * @param int $id The Review's id
	 * @return DataResponse<Http::STATUS_OK, array{review: IaReview}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The Review
	 * 404: No such Review of the caller's, nor an automatic one, or they can't open its document any more
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
	 * Editor, and whether they may open «Estado» (an admin, or a group the admin page is delegated to)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{editorGroups: list<IaGroup>, admin: bool}, array{}>
	 *
	 * 200: The caller's Editor groups and whether «Estado» is theirs
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/me')]
	public function me(): DataResponse {
		return new DataResponse([
			'editorGroups' => $this->named($this->access->editorGroups($this->user())),
			'admin' => $this->access->admin($this->user()),
		]);
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
	 * «Estado»: whether the Model answers, the work in line by kind, the last Run that ended well and
	 * the failed Runs, newest first, with their last error
	 *
	 * @return DataResponse<Http::STATUS_OK, IaEstado, array{}>
	 *
	 * 200: «IA»'s health
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/estado')]
	public function adminEstado(): DataResponse {
		return new DataResponse($this->estado->view());
	}

	/**
	 * «Reintentar»: put a failed Run back in line, from its last finished step
	 *
	 * @param int $id The Run's id
	 * @return DataResponse<Http::STATUS_OK, IaEstado, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: Back in line; «IA»'s health now
	 * 404: No such Run
	 * 422: It hasn't failed, or a newer Edition of its Bulletin has passed it
	 */
	#[AuthorizedAdminSetting(settings: Admin::class)]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/runs/{id}/retry')]
	public function retryRun(int $id): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse($this->estado->retry($id)), self::NO_RUN);
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
	 * An Edition: its Resumen, written by AI ('' when no document could be read), its sections, each
	 * document with its Reading, and the caller's Personal list
	 *
	 * @param int $id The Edition's id
	 * @return DataResponse<Http::STATUS_OK, array{edition: IaEdition}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_GONE, array{message: string}, array{}>
	 *
	 * 200: The Edition; for its Editors, also one they withdrew
	 * 404: No such Edition, it isn't published yet, or the caller can't see it
	 * 410: Its Editors withdrew it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/editions/{id}')]
	public function edition(int $id): DataResponse {
		try {
			return new DataResponse(['edition' => $this->editions->one($this->user(), $id)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => self::NO_EDITION], Http::STATUS_NOT_FOUND);
		} catch (Withdrawn $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_GONE);
		}
	}

	/**
	 * Correct an Edition's Resumen, as an Editor of its Bulletin: its Readers see who corrected it
	 *
	 * @param int $id The Edition's id
	 * @param string $resumen The corrected Resumen
	 * @return DataResponse<Http::STATUS_OK, array{edition: IaEdition}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: Corrected
	 * 403: The caller isn't one of its Bulletin's Editors
	 * 404: No such Edition, it isn't published yet, or the caller can't see it
	 * 422: The Resumen is empty
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/editions/{id}')]
	public function correctEdition(int $id, string $resumen): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['edition' => $this->editions->correct($this->user(), $id, $resumen)]), self::NO_EDITION);
	}

	/**
	 * Withdraw an Edition, as an Editor of its Bulletin: at once from its Readers' pages, «Para ti» and the widget
	 *
	 * @param int $id The Edition's id
	 * @return DataResponse<Http::STATUS_OK, array{edition: IaEdition}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Withdrawn
	 * 403: The caller isn't one of its Bulletin's Editors
	 * 404: No such Edition, it isn't published yet, or the caller can't see it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/editions/{id}/withdrawal')]
	public function withdrawEdition(int $id): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['edition' => $this->editions->withdraw($this->user(), $id)]), self::NO_EDITION);
	}

	/**
	 * Undo an Edition's withdrawal, as an Editor of its Bulletin: its Readers see it again
	 *
	 * @param int $id The Edition's id
	 * @return DataResponse<Http::STATUS_OK, array{edition: IaEdition}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Back for its Readers
	 * 403: The caller isn't one of its Bulletin's Editors
	 * 404: No such Edition, it isn't published yet, or the caller can't see it
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/editions/{id}/withdrawal')]
	public function restoreEdition(int $id): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['edition' => $this->editions->restore($this->user(), $id)]), self::NO_EDITION);
	}

	/**
	 * «Acciones automáticas»: the Automatic actions of the caller's Editor groups, and the admin's
	 * Flow rules «Revisar con IA», read-only
	 *
	 * @return DataResponse<Http::STATUS_OK, array{actions: list<IaAction>, flowRules: list<IaFlowRule>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The actions and the Flow rules, by name
	 * 403: The caller is no Editor
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/actions')]
	public function actions(): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse(['actions' => $this->actions->all($this->user()), 'flowRules' => $this->flowRules->all()]));
	}

	/**
	 * Put an Automatic action on a folder: every file added to it, changed in it, moved or copied into it is reviewed in Off hours
	 *
	 * @param string $name The action's name
	 * @param string $editorGroup The caller's Editor group that owns it
	 * @param string $folder The folder's file id: one the whole Editor group can open
	 * @param string $instructions What the Reviews should also check, up to 1000 characters; '' for none
	 * @return DataResponse<Http::STATUS_CREATED, array{action: IaAction}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: The action
	 * 403: The caller isn't in that Editor group
	 * 422: A field isn't one, or a member of the Editor group can't open the folder
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/actions')]
	public function createAction(string $name, string $editorGroup, string $folder, string $instructions = ''): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse([
			'action' => $this->actions->create($this->user(), ['name' => $name, 'editorGroup' => $editorGroup, 'folder' => $folder, 'instructions' => $instructions]),
		], Http::STATUS_CREATED), self::NO_ACTION);
	}

	/**
	 * Change an Automatic action of the caller's Editor group: its name, folder and instructions
	 *
	 * @param int $id The action's id
	 * @param string $name The action's name
	 * @param string $folder The folder's file id: one the whole Editor group can open
	 * @param string $instructions What the Reviews should also check, up to 1000 characters; '' for none
	 * @return DataResponse<Http::STATUS_OK, array{action: IaAction}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The action, changed
	 * 403: The caller isn't in its Editor group
	 * 404: No such action
	 * 422: A field isn't one, or a member of the Editor group can't open the folder
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/actions/{id}')]
	public function changeAction(int $id, string $name, string $folder, string $instructions = ''): DataResponse {
		return $this->refusable(fn (): DataResponse => new DataResponse([
			'action' => $this->actions->change($this->user(), $id, ['name' => $name, 'folder' => $folder, 'instructions' => $instructions]),
		]), self::NO_ACTION);
	}

	/**
	 * Delete an Automatic action of the caller's Editor group: no new Review comes from it; its reports stay
	 *
	 * @param int $id The action's id
	 * @return DataResponse<Http::STATUS_OK, array{}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Deleted
	 * 403: The caller isn't in its Editor group
	 * 404: No such action
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/actions/{id}')]
	public function deleteAction(int $id): DataResponse {
		return $this->refusable(function () use ($id): DataResponse {
			$this->actions->delete($this->user(), $id);
			return new DataResponse([]);
		}, self::NO_ACTION);
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
	 * $answer's response, or the Spanish reason it was refused: 403, 404 ($notFound) or 422.
	 *
	 * @param callable(): DataResponse $answer
	 */
	private function refusable(callable $answer, string $notFound = self::NO_BULLETIN): DataResponse {
		try {
			return $answer();
		} catch (Forbidden $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $notFound], Http::STATUS_NOT_FOUND);
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
