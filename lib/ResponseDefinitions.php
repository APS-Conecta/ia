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
 * @psalm-type IaReviewItem = array{
 *     id: int,
 *     fileId: int,
 *     etag: string,
 *     name: string,
 *     state: 'waiting'|'working'|'done'|'failed',
 *     step: int,
 *     steps: int,
 *     error: ?string,
 *     requestedAt: int,
 * }
 *
 * @psalm-type IaReview = IaReviewItem&array{
 *     findings: ?list<IaFinding>,
 * }
 */
class ResponseDefinitions {
}
