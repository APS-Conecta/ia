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
 *     origin: 'manual'|'action',
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
 * @psalm-type IaEditionItem = array{
 *     id: int,
 *     date: string,
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
 */
class ResponseDefinitions {
}
