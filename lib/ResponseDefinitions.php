<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia;

/**
 * The shapes the OCS API answers with, for openapi.json.
 *
 * @psalm-type IaFinding = array{
 *     kind: 'error'|'missing'|'unclear',
 *     where: string,
 *     quote: ?string,
 *     text: string,
 *     fix: string,
 * }
 *
 * @psalm-type IaOffHours = array{
 *     start: string,
 *     end: string,
 * }
 *
 * @psalm-type IaReviewItem = array{
 *     id: int,
 *     fileId: int,
 *     etag: string,
 *     name: string,
 *     state: 'waiting'|'working'|'done'|'failed',
 *     step: int,
 *     steps: int,
 *     offHours: bool,
 *     offHoursWindow: IaOffHours,
 *     error: ?string,
 *     requestedAt: int,
 *     origin: 'manual'|'action'|'flow',
 *     instructions: ?string,
 * }
 *
 * @psalm-type IaReview = IaReviewItem&array{
 *     findings: ?list<IaFinding>,
 * }
 *
 * @psalm-type IaGroup = array{
 *     id: string,
 *     name: string,
 * }
 *
 * @psalm-type IaSource = array{
 *     type: 'folder'|'file'|'calendar',
 *     id: string,
 *     name: string,
 * }
 *
 * @psalm-type IaSchedule = array{
 *     kind: 'daily'|'weekly'|'monthly'|'sources',
 *     day: ?int,
 * }
 *
 * @psalm-type IaBulletinItem = array{
 *     id: int,
 *     name: string,
 *     role: 'editor'|'reader',
 * }
 *
 * @psalm-type IaBulletin = IaBulletinItem&array{
 *     editorGroup: IaGroup,
 *     sources: list<IaSource>,
 *     readers: list<IaGroup>,
 *     schedule: IaSchedule,
 *     due: ?string,
 *     writing: ?IaWriting,
 * }
 *
 * @psalm-type IaAction = array{
 *     id: int,
 *     name: string,
 *     editorGroup: IaGroup,
 *     folder: array{id: string, name: string},
 *     instructions: string,
 * }
 *
 * @psalm-type IaDocumentItem = array{
 *     fileId: int,
 *     name: string,
 *     folder: string,
 *     status: 'read'|'scan'|'failed'|'unread',
 *     expires: ?string,
 *     cargos: list<string>,
 *     review: ?array{id: int, findings: int},
 *     modifiedAt: int,
 * }
 *
 * @psalm-type IaAppearance = array{
 *     bulletin: array{id: int, name: string},
 *     edition: array{id: int, date: string},
 * }
 *
 * @psalm-type IaDocument = IaDocumentItem&array{
 *     summary: ?string,
 *     readAt: ?int,
 *     latestReview: ?IaReview,
 *     appearsIn: list<IaAppearance>,
 * }
 *
 * @psalm-type IaDocuments = array{
 *     documents: list<IaDocumentItem>,
 *     counts: array{all: int, changed: int, expiring: int, findings: int, cargo: int},
 * }
 *
 * @psalm-type IaFlowRule = array{
 *     id: int,
 *     name: string,
 *     instructions: string,
 * }
 *
 * @psalm-type IaEditionItem = array{
 *     id: int,
 *     date: string,
 *     late: bool,
 *     withdrawn: bool,
 * }
 *
 * @psalm-type IaWriting = array{
 *     date: string,
 *     step: int,
 *     steps: int,
 *     late: bool,
 * }
 *
 * @psalm-type IaEditionDocument = array{
 *     fileId: int,
 *     name: string,
 *     new: bool,
 *     status: 'read'|'scan'|'failed',
 *     summary: ?string,
 *     readAt: ?int,
 *     expires: ?string,
 *     cargos: list<string>,
 * }
 *
 * @psalm-type IaMeeting = array{
 *     id: string,
 *     title: string,
 *     start: int,
 *     end: ?int,
 *     calendar: string,
 * }
 *
 * @psalm-type IaPersonalList = array{
 *     meetings: list<IaMeeting>,
 *     documents: list<IaEditionDocument>,
 * }
 *
 * @psalm-type IaEditionSummary = IaEditionItem&array{
 *     bulletin: array{id: int, name: string},
 * }
 *
 * @psalm-type IaEdition = IaEditionSummary&array{
 *     resumen: string,
 *     correctedBy: ?string,
 *     changed: list<IaEditionDocument>,
 *     expiring: list<IaEditionDocument>,
 *     undated: list<IaEditionDocument>,
 *     unreadable: list<IaEditionDocument>,
 *     personal: IaPersonalList,
 * }
 *
 * @psalm-type IaParaTi = array{
 *     bulletins: list<IaBulletinItem>,
 *     editions: list<IaEditionSummary>,
 *     meetings: list<IaMeeting>,
 *     documents: list<IaEditionDocument>,
 *     expiring: list<IaEditionDocument>,
 * }
 *
 * @psalm-type IaReadersPreview = array{
 *     readers: int,
 *     leftOut: list<array{id: string, name: string, sources: list<string>}>,
 * }
 *
 * @psalm-type IaAdminSettings = array{
 *     editorGroups: list<IaGroup>,
 *     offHours: IaOffHours,
 *     timezone: string,
 * }
 *
 * @psalm-type IaModelHealth = array{
 *     state: 'up'|'down'|'unknown',
 *     since: ?int,
 *     answered: ?int,
 *     alerted: bool,
 *     provider: bool,
 * }
 *
 * @psalm-type IaRunItem = array{
 *     id: int,
 *     kind: 'manual'|'action'|'flow'|'edition',
 *     name: ?string,
 *     date: ?string,
 *     error: ?string,
 *     at: int,
 * }
 *
 * @psalm-type IaEstado = array{
 *     model: IaModelHealth,
 *     waiting: array{manual: int, action: int, flow: int, edition: int},
 *     lastDone: ?IaRunItem,
 *     failed: list<IaRunItem>,
 * }
 */
class ResponseDefinitions {
}
