<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A Run (gestion-aio#162, Runs): one piece of AI work, unique per (kind, target). A review's target
 * is its report's id. `results` holds what the finished steps gave, as JSON, until the last one.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getTarget()
 * @method void setTarget(string $target)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getStep()
 * @method void setStep(int $step)
 * @method int getSteps()
 * @method void setSteps(int $steps)
 * @method int|null getTaskId()
 * @method void setTaskId(?int $taskId)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getResults()
 * @method void setResults(?string $results)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Run extends Entity {
	public const REVIEW = 'review';

	public const WAITING = 'waiting';
	public const WORKING = 'working';
	public const DONE = 'done';
	public const FAILED = 'failed';

	// Null until set: Entity writes only the fields a setter changed.
	protected ?string $kind = null;
	protected ?string $target = null;
	protected ?string $state = null;
	protected ?int $step = null;
	protected ?int $steps = null;
	protected ?int $taskId = null;
	protected ?string $lastError = null;
	protected ?string $actor = null;
	protected ?string $results = null;
	protected ?int $updatedAt = null;

	public function __construct() {
		$this->addType('step', 'integer');
		$this->addType('steps', 'integer');
		$this->addType('taskId', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
