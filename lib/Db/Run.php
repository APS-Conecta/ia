<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A Run (gestion-aio#162, Runs): one piece of AI work, unique per (kind, target). A review's target
 * is its report's id. `results` holds what the finished steps gave, as JSON, until the last one,
 * so a Run put back in line resumes from its last finished step. An Off-hours Run is asked only
 * in Off hours.
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
 * @method bool|null getOffHours()
 * @method void setOffHours(bool $offHours)
 * @method int|null getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getResults()
 * @method void setResults(?string $results)
 * @method int|null getRule()
 * @method void setRule(?int $rule)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getVersion()
 * @method void setVersion(int $version)
 */
class Run extends Entity {
	public const REVIEW = 'review';
	public const EDITION = 'edition';

	public const WAITING = 'waiting';
	public const WORKING = 'working';
	public const DONE = 'done';
	public const FAILED = 'failed';

	/** Asks of one step that may fail before the Run fails with its last error. */
	public const ATTEMPTS = 3;

	// Null until set: Entity writes only the fields a setter changed.
	protected ?string $kind = null;
	protected ?string $target = null;
	protected ?string $state = null;
	protected ?int $step = null;
	protected ?int $steps = null;
	protected ?int $taskId = null;
	protected ?bool $offHours = null;
	protected ?int $attempts = null;
	protected ?string $lastError = null;
	protected ?string $actor = null;
	/** The Automatic action an automatic Review is for; null for any other Run. */
	protected ?int $rule = null;
	protected ?string $results = null;
	protected ?int $updatedAt = null;
	/** Bumped by every save: a save from a Run read before another one's is refused (RunMapper::save). */
	protected ?int $version = null;

	public function __construct() {
		$this->addType('step', 'integer');
		$this->addType('steps', 'integer');
		$this->addType('taskId', 'integer');
		$this->addType('rule', 'integer');
		$this->addType('offHours', 'boolean');
		$this->addType('attempts', 'integer');
		$this->addType('updatedAt', 'integer');
		$this->addType('version', 'integer');
	}

	/** Its step's ask failed: back in line for the next tick, or failed with $error after the last attempt. */
	public function retry(string $error): void {
		$this->setAttempts(($this->getAttempts() ?? 0) + 1);
		$this->setLastError($error);
		$this->setTaskId(null);
		$this->setState($this->getAttempts() >= self::ATTEMPTS ? self::FAILED : self::WAITING);
	}

	/** Where it comes from: a Review asked for by hand, an Automatic action's Review, or an Edition. @return 'manual'|'action'|'edition' */
	public function origin(): string {
		return match (true) {
			$this->getKind() === self::EDITION => 'edition',
			$this->getRule() !== null => 'action',
			default => 'manual',
		};
	}

	/** «Reintentar»: the failed Run back in line from its last finished step, its attempts afresh. */
	public function requeue(): void {
		$this->setState(self::WAITING);
		$this->setAttempts(0);
		$this->setLastError(null);
		$this->setTaskId(null);
	}

	/** It can't go on (its file is gone or unreadable): failed now with $error, whatever the attempts. */
	public function fail(string $error): void {
		$this->setState(self::FAILED);
		$this->setLastError($error);
		$this->setTaskId(null);
		$this->setResults(null);
	}
}
