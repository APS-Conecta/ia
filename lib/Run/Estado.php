<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Run;

use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\Health;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * «Estado» (APS-Conecta/ia#8): the health of «IA» on its admin page. It shows whether the Model
 * answers, the work in line by kind, the last Run that ended well and the failed ones with their
 * last error. «Reintentar» puts a failed one back in line.
 *
 * @psalm-import-type IaEstado from ResponseDefinitions
 * @psalm-import-type IaRunItem from ResponseDefinitions
 */
class Estado {
	public function __construct(
		private RunMapper $runs,
		private Kinds $kinds,
		private Health $health,
	) {
	}

	/** @return IaEstado */
	public function view(): array {
		$waiting = ['manual' => 0, 'action' => 0, 'flow' => 0, 'edition' => 0];
		foreach ($this->runs->inState(Run::WAITING, Run::WORKING) as $run) {
			$waiting[$run->origin()]++;
		}
		$last = $this->runs->latest(Run::DONE);
		$failed = $this->runs->inState(Run::FAILED);
		usort($failed, static fn (Run $a, Run $b): int => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);
		return [
			'model' => $this->health->status(),
			'waiting' => $waiting,
			'lastDone' => $last === null ? null : $this->item($last),
			'failed' => array_map($this->item(...), $failed),
		];
	}

	/**
	 * «Reintentar».
	 *
	 * @return IaEstado
	 * @throws DoesNotExistException when there is no such Run
	 * @throws Refused when it hasn't failed, or can't go back in line
	 */
	public function retry(int $id): array {
		$run = $this->runs->byId($id) ?? throw new DoesNotExistException('No such Run');
		if ($run->getState() !== Run::FAILED || !$this->kinds->of($run)->requeue($run)) {
			throw new Refused('Este trabajo ya no está fallado.');
		}
		return $this->view();
	}

	/** @return IaRunItem */
	private function item(Run $run): array {
		return ['id' => $run->getId(), 'kind' => $run->origin()] + $this->kinds->of($run)->about($run)
			+ ['error' => $run->getState() === Run::FAILED ? (string)$run->getLastError() : null, 'at' => (int)$run->getUpdatedAt()];
	}
}
