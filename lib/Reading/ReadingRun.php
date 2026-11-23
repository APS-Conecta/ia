<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Reading;

use DateTimeInterface;
use OCA\Ia\Access\Access;
use OCA\Ia\Action\Actions;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Action;
use OCA\Ia\Db\ActionMapper;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Run\Kind;
use OCA\Ia\Run\Steps;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A Reading's Run (APS-Conecta/ia#42): the Reading of a document that only an Automatic action
 * watches, which no Edition would take. Editions take their Sources' Readings themselves.
 *
 * - **When.** Once a night, at the start of Off hours (ReadingSweepJob), every Automatic action's
 *   folder is swept: each document there without a Reading of its current version gets a Run, one
 *   per file, in line after the other Off-hours Runs (Runs). One that failed on that version waits
 *   for «Reintentar»; a new version, or another action's sweep after the first's was deleted, puts
 *   it back in line. An action whose folder no member of its Editor group can open gets none.
 * - **As whom.** An active member of the action's Editor group who can open the file
 *   (Actions::actor): a Reading is the same for everyone. When none can, or the action is gone,
 *   the Run fails into «Estado» and nobody is told; a file deleted meanwhile just ends it, as an
 *   Edition drops it.
 * - **Steps.** Those of any Reading (Readings::ask): each part of a long document, then their
 *   merge. A scan or a file «IA» can't read gets its Reading at once, without the Model. A version
 *   read meanwhile, by an Edition, ends the Run; one that changed since the ask starts over.
 *
 * Its progress is the Run's results: the version it reads and Readings' progress.
 *
 * @psalm-import-type IaReadingProgress from Readings
 */
class ReadingRun implements Kind {
	private const SWEPT = 'readings_swept';

	public function __construct(
		private RunMapper $runs,
		private ActionMapper $actionMapper,
		private Actions $actions,
		private Access $access,
		private Readings $readings,
		private Steps $steps,
		private ModelGateway $model,
		private Settings $settings,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** The night's sweep, once per Off hours: a Run for each action folder's document read in no version of its own. */
	public function due(DateTimeInterface $now): void {
		$night = $this->settings->offHoursDate($now);
		if ($this->appConfig->getValueString(Application::APP_ID, self::SWEPT) === $night) {
			return;
		}
		foreach ($this->actionMapper->all() as $action) {
			try {
				$actor = $this->actions->actor($action, $action->getFolder(), 'folder');
				$folder = $actor === null ? null : $this->access->node($actor, $action->getFolder(), 'folder');
				foreach ($this->access->documents($folder) as $file) {
					if ($this->readings->of($file->getId(), $file->getEtag()) === null) {
						$this->open($file, $action);
					}
				}
			} catch (Throwable $e) {
				// By its id and the error's class only: a message may name a file. The others go on.
				$this->logger->error('«IA» could not sweep the folder of the Automatic action {action}: {error}', ['action' => $action->getId(), 'error' => $e::class]);
			}
		}
		$this->appConfig->setValueString(Application::APP_ID, self::SWEPT, $night);
	}

	public function resume(Run $run): void {
		$file = $this->source($run);
		if ($file !== null) {
			$this->next($run, $file, self::state($run));
		}
	}

	public function finish(Run $run, Task $task, ?string $error): void {
		$this->model->forget($task);
		if ($run->getTaskId() !== $task->getId() || $run->getState() !== Run::WORKING) {
			return;
		}
		if ($error !== null) {
			$run->retry($error);
			$this->runs->save($run);
			return;
		}
		$file = $this->source($run);
		$state = self::state($run);
		if ($file === null || $state === null) {
			return;
		}
		$document = $state['etag'] === $file->getEtag() ? $this->readings->text($file) : null;
		if ($document === null) {
			// Changed since the ask (the answer is of no version to keep), or unreadable now.
			$this->next($run, $file, $state);
			return;
		}
		$taken = $this->readings->take((string)($task->getOutput()['output'] ?? ''), $document, $file->getId(), $state['etag'], $state['progress']);
		if ($taken === Taken::Empty) {
			$run->retry('El modelo respondió vacío.');
			$this->runs->save($run);
			return;
		}
		$run->setAttempts(0);
		$run->setStep($run->getStep() + 1);
		if ($taken === Taken::Read) {
			$this->done($run);
			return;
		}
		$this->next($run, $file, $state);
	}

	/** Back in line from its last finished step; one whose progress was dropped starts over. */
	public function requeue(Run $run): bool {
		$run->requeue();
		return $this->runs->save($run);
	}

	public function about(Run $run): array {
		return ['name' => $this->actionMapper->byId((int)$run->getRule())?->getName(), 'date' => null];
	}

	/** A Run for the file's Reading, unless one is in line, under way, or failed on this version. */
	private function open(File $file, Action $action): void {
		$run = $this->runs->of(Run::READING, (string)$file->getId());
		if ($run !== null && match ($run->getState()) {
			Run::DONE => false,
			Run::FAILED => (self::state($run)['etag'] ?? null) === $file->getEtag(),
			default => true,
		}) {
			return;
		}
		$run ??= new Run();
		$run->setRule($action->getId());
		$run->setState(Run::WAITING);
		$run->setOffHours(true);
		$run->setStep(0);
		$run->setSteps(0);
		$run->setAttempts(0);
		$run->setLastError(null);
		$run->setTaskId(null);
		$run->setResults(null);
		if ($run->getId() !== null) {
			$this->runs->save($run);
			return;
		}
		$run->setKind(Run::READING);
		$run->setTarget((string)$file->getId());
		$run->setVersion(0);
		$run->setUpdatedAt($this->time->getTime());
		try {
			$this->runs->insert($run);
		} catch (Exception $e) {
			// Another process opened it first.
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * Its next ask; none once the version is read, by this Run or meanwhile by an Edition, or when
	 * it can't be (a scan, a file «IA» can't read): it is done. Its first ask, or one of a new
	 * version, is of the first part.
	 *
	 * @param ?array{etag: string, progress: IaReadingProgress} $state
	 */
	private function next(Run $run, File $file, ?array $state): void {
		$document = $this->readings->of($file->getId(), $file->getEtag()) === null ? $this->readings->text($file) : null;
		if ($document === null) {
			$this->done($run);
			return;
		}
		if ($state === null || $state['etag'] !== $file->getEtag()) {
			$state = ['etag' => $file->getEtag(), 'progress' => ['part' => 0, 'parts' => []]];
			$run->setStep(0);
		}
		$run->setSteps($this->readings->asks($document));
		$run->setResults(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		if (!$this->settings->isOffHours($this->time->getDateTime())) {
			// No new Off-hours step once Off hours are over: it waits for the next ones.
			$run->setTaskId(null);
			$run->setState(Run::WAITING);
			$this->runs->save($run);
			return;
		}
		$this->steps->ask($run, $this->readings->ask($document, $state['progress']));
	}

	/** The file, as an active member of the action's Editor group opens it; null when the Run can't go on: ended when the file is gone, else failed. */
	private function source(Run $run): ?File {
		$fileId = (int)$run->getTarget();
		$action = $this->actionMapper->byId((int)$run->getRule());
		$actor = $action === null ? null : $this->actions->actor($action, $fileId);
		$file = $actor === null ? null : $this->access->file($actor, $fileId);
		if ($file === null && !$this->access->exists($fileId)) {
			$this->done($run);
		} elseif ($file === null) {
			$run->fail($action === null ? 'Se eliminó la acción automática.' : 'Nadie del grupo editor de la acción puede abrir el archivo.');
			$this->runs->save($run);
		}
		return $file;
	}

	private function done(Run $run): void {
		$run->setState(Run::DONE);
		$run->setTaskId(null);
		$run->setResults(null);
		$this->runs->save($run);
	}

	/** @return ?array{etag: string, progress: IaReadingProgress} */
	private static function state(Run $run): ?array {
		return json_decode((string)$run->getResults(), true) ?: null;
	}
}
