<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Review;

use OCA\Ia\Access\Access;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Report;
use OCA\Ia\Db\ReportMapper;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Db\StaleRun;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Reading\Document;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Refused;
use OCA\Ia\ResponseDefinitions;
use OCA\Ia\Run\Kind;
use OCA\Ia\Run\Steps;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Task;

/**
 * Reviews (CONTEXT.md; gestion-aio#162, Reviews): the Model checks one document and reports its
 * errors, gaps and unclear parts, each with a fix. The document is never changed.
 *
 * - **Steps.** A one-chunk document gets a single pass for all three kinds. A longer one gets a
 *   pass per chunk, for Error and Poco claro plus the chunk's summary, then a last pass over the
 *   summaries for Falta. Each step is asked when the one before it succeeds.
 * - **As the requester.** A manual report is theirs alone, and only while they can open the
 *   file; every step reads the file as them.
 * - **Short now, longer in Off hours.** A document of about 10 pages or less is asked at once; a
 *   longer one waits for Off hours, where the run engine (Runs) asks it, and an Off-hours Run
 *   stops between steps when Off hours end, to resume from its last finished step the next time.
 * - **Retries.** A failed ask puts the Run back in line for the engine's next tick; the last of
 *   Run::ATTEMPTS fails it with its error.
 * - **One Run per file and person.** Asking again for a file under review answers that Review.
 *
 * @psalm-import-type IaFinding from ResponseDefinitions
 * @psalm-import-type IaReview from ResponseDefinitions
 * @psalm-import-type IaReviewItem from ResponseDefinitions
 */
class Reviews implements Kind {
	use TTransactional;

	/** A short document, reviewed at once: about 10 pages. */
	public const SHORT_PAGES = 10;

	private const KINDS = 'Busca:
- Error: algo incorrecto o que se contradice con otra parte del documento.
- Poco claro: una frase ambigua o difícil de entender.';
	private const MISSING = '- Falta: algo que un documento así debería tener y no tiene.';
	private const FORMAT = 'Escribe un hallazgo por línea, con este formato exacto:
TIPO: Error | CITA: frase copiada del documento, tal cual | PROBLEMA: qué está mal | ARREGLO: cómo corregirlo
En CITA copia las palabras exactas del documento, sin cambiarlas.';

	private const SINGLE = 'Revisa el documento de un centro de salud que está entre las marcas <<< y >>>. ' . self::KINDS . "\n" . self::MISSING . "\n\n"
		. self::FORMAT . ' En una Falta escribe CITA: - y agrega | DONDE: sección donde debería ir' . "\n"
		. 'Escribe solo los hallazgos, sin títulos ni explicaciones. ' . ModelGateway::CLOSE;
	private const CHUNK = 'Revisa la parte %d de %d de un documento de un centro de salud; la parte está entre las marcas <<< y >>>. ' . self::KINDS . "\n\n"
		. "Primero escribe una línea con un resumen de la parte, así:\nRESUMEN: qué dice esta parte, en dos o tres oraciones\nDespués:\n"
		. self::FORMAT . "\n" . 'Escribe solo el resumen y los hallazgos, sin títulos ni explicaciones. ' . ModelGateway::CLOSE;
	private const GAPS = 'Estos son los resúmenes de las %d partes de un documento de un centro de salud, entre las marcas <<< y >>>. '
		. "Di qué le falta: algo que un documento así debería tener y no tiene.\n\n"
		. "Escribe una falta por línea, con este formato exacto:\nTIPO: Falta | DONDE: parte o sección donde debería ir | PROBLEMA: qué falta | ARREGLO: qué agregar\n"
		. 'Escribe solo las faltas, sin títulos ni explicaciones. ' . ModelGateway::CLOSE;

	public function __construct(
		private Access $access,
		private TextReader $reader,
		private ModelGateway $model,
		private ReportMapper $reports,
		private RunMapper $runs,
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private IDBConnection $db,
		private Settings $settings,
	) {
	}

	/**
	 * Reviews the file $fileId for $userId: now when it is short, in Off hours otherwise. A file
	 * they already have a Review of is that Review, asked again when it failed or the file changed.
	 *
	 * @return array{review: IaReview, start: 'now'|'offHours', created: bool}
	 * @throws NotFoundException when they can't open it
	 * @throws Refused when «IA» can't read its format yet
	 */
	public function create(string $userId, int $fileId): array {
		$file = $this->access->file($userId, $fileId) ?? throw new NotFoundException();
		$report = $this->reports->manualOf($userId, $fileId);
		if ($report !== null) {
			$run = $this->runs->of(Run::REVIEW, (string)$report->getId()) ?? throw new NotFoundException();
			if (!self::underWay($run) && ($run->getState() === Run::FAILED || $file->getEtag() !== $report->getEtag())) {
				$report->setCreatedAt($this->time->getTime());
				$this->begin($run, $this->restart($report, $run, $file, $this->reader->read($file)));
			}
			return $this->answer($report, $run, $file) + ['created' => false];
		}
		$document = $this->reader->read($file);
		$report = new Report();
		$report->setFileId($fileId);
		$report->setEtag($file->getEtag());
		$report->setOrigin(Report::MANUAL);
		$report->setRequester($userId);
		$report->setCreatedAt($this->time->getTime());
		$run = new Run();
		$run->setKind(Run::REVIEW);
		$run->setActor($userId);
		$run->setVersion(0);
		$run->setUpdatedAt($this->time->getTime());
		$chunks = $this->line($run, $document);
		try {
			$this->atomic(function () use ($report, $run): void {
				$this->reports->insert($report);
				$run->setTarget((string)$report->getId());
				$this->runs->insert($run);
			}, $this->db);
		} catch (Exception $e) {
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			return $this->create($userId, $fileId);
		}
		if (!$run->getOffHours()) {
			$this->steps->ask($run, $this->prompt($run, $chunks, []));
		}
		return $this->answer($report, $run, $file) + ['created' => true];
	}

	/**
	 * Reviews again the current version of the file a report of $userId's is about.
	 *
	 * @return array{review: IaReview, start: 'now'|'offHours'}
	 * @throws NotFoundException
	 * @throws Refused while it is still under way, or when «IA» can't read its format
	 */
	public function again(string $userId, int $id): array {
		[$report, $run, $file] = $this->mine($userId, $id);
		if (self::underWay($run)) {
			throw new Refused('Esta revisión sigue en curso.');
		}
		$report->setCreatedAt($this->time->getTime());
		$this->begin($run, $this->restart($report, $run, $file, $this->reader->read($file)));
		return $this->answer($report, $run, $file);
	}

	/**
	 * @return IaReview
	 * @throws NotFoundException
	 */
	public function one(string $userId, int $id): array {
		[$report, $run, $file] = $this->mine($userId, $id);
		return $this->view($report, $run, $file, true);
	}

	/** @return list<IaReviewItem> $userId's Reviews of files they can still open, newest first */
	public function all(string $userId): array {
		$items = [];
		foreach ($this->reports->requestedBy($userId) as $report) {
			$file = $this->access->file($userId, $report->getFileId());
			$run = $this->runs->of(Run::REVIEW, (string)$report->getId());
			if ($file !== null && $run !== null) {
				$items[] = $this->view($report, $run, $file, false);
			}
		}
		return $items;
	}

	/**
	 * Asks a waiting Review Run's current step, as the run engine decides, from its last finished
	 * step; a Run whose file changed since starts over, sized anew, and a file grown past the short
	 * size waits for Off hours.
	 */
	public function resume(Run $run): void {
		$source = $this->source($run);
		if ($source === null) {
			return;
		}
		[$report, $file, $document] = $source;
		if ($file->getEtag() !== $report->getEtag()) {
			$this->begin($run, $this->restart($report, $run, $file, $document));
			return;
		}
		$this->steps->ask($run, $this->prompt($run, self::chunks($document), self::results($run)['summaries']));
	}

	/**
	 * Takes the result of a Review Run's task, as TaskListener (or the engine, for an answer whose
	 * event was lost) hands it over: stores the step, then asks the next one, or finishes. A task
	 * the Run no longer waits for is only deleted; of two processes finishing the same task, the
	 * one that saves first goes on and the other's work is rolled back.
	 */
	public function finish(Run $run, Task $task, ?string $error): void {
		if ($run->getTaskId() !== $task->getId() || $run->getState() !== Run::WORKING) {
			$this->model->forget($task);
			return;
		}
		if ($error !== null) {
			$this->model->forget($task);
			$run->retry($error);
			$this->runs->save($run);
			return;
		}
		$source = $this->source($run);
		if ($source === null) {
			$this->model->forget($task);
			return;
		}
		[$report, $file, $document] = $source;
		if ($file->getEtag() !== $report->getEtag()) {
			$this->model->forget($task);
			$this->begin($run, $this->restart($report, $run, $file, $document));
			return;
		}
		$results = self::results($run);
		$lines = ModelGateway::lines((string)($task->getOutput()['output'] ?? ''));
		$chunks = self::chunks($document);
		if ($run->getSteps() === 1) {
			$results['findings'] = Findings::from($lines, $document, [Findings::ERROR, Findings::UNCLEAR, Findings::MISSING]);
		} elseif ($run->getStep() <= count($chunks)) {
			$results['findings'] = [...$results['findings'], ...Findings::from($lines, $document, [Findings::ERROR, Findings::UNCLEAR])];
			$results['summaries'][] = self::summary($lines);
		} else {
			$results['findings'] = [...$results['findings'], ...Findings::from($lines, $document, [Findings::MISSING])];
		}
		$this->model->forget($task);
		$run->setResults(json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$run->setAttempts(0);
		if ($run->getStep() < $run->getSteps()) {
			$run->setStep($run->getStep() + 1);
			if ($run->getOffHours() && !$this->settings->isOffHours($this->time->getDateTime())) {
				$run->setState(Run::WAITING);
				$run->setTaskId(null);
				$this->runs->save($run);
				return;
			}
			$this->steps->ask($run, $this->prompt($run, $chunks, $results['summaries']));
			return;
		}
		$report->setFindings(json_encode(array_values(array_unique($results['findings'], SORT_REGULAR)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$run->setState(Run::DONE);
		$run->setTaskId(null);
		$run->setResults(null);
		try {
			$this->atomic(function () use ($report, $run): void {
				if (!$this->runs->save($run)) {
					throw new StaleRun();
				}
				$this->reports->update($report);
			}, $this->db);
		} catch (StaleRun) {
			return;
		}
		$this->notify($report, $file);
	}

	/**
	 * The Run's report, its file as the actor opens it now, and the Document read from it; a Run
	 * whose file is gone or unreadable fails, and null comes back.
	 *
	 * @return array{0: Report, 1: File, 2: Document}|null
	 */
	private function source(Run $run): ?array {
		$report = $this->reports->byId((int)$run->getTarget());
		$file = $report === null ? null : $this->access->file((string)$run->getActor(), $report->getFileId());
		try {
			if ($report !== null && $file !== null) {
				return [$report, $file, $this->reader->read($file)];
			}
			$run->fail('El archivo ya no está disponible.');
		} catch (Refused $e) {
			$run->fail($e->getMessage());
		}
		$this->runs->save($run);
		return null;
	}

	/**
	 * The report's file reviewed from its current version, $document: the report starts over and
	 * the Run goes back to its first step (Reviews::line).
	 *
	 * @return list<string> the chunks
	 */
	private function restart(Report $report, Run $run, File $file, Document $document): array {
		$report->setEtag($file->getEtag());
		$report->setFindings(null);
		$this->reports->update($report);
		return $this->line($run, $document);
	}

	/**
	 * Puts the Run at the first step of a Review of $document, waiting, unsaved: Off hours for a
	 * document over the short size, as its own page count says.
	 *
	 * @return list<string> the chunks
	 */
	private function line(Run $run, Document $document): array {
		$chunks = self::chunks($document);
		$run->setStep(1);
		$run->setSteps(count($chunks) === 1 ? 1 : count($chunks) + 1);
		$run->setOffHours($document->pages > self::SHORT_PAGES);
		$run->setAttempts(0);
		$run->setLastError(null);
		$run->setResults(null);
		$run->setState(Run::WAITING);
		$run->setTaskId(null);
		return $chunks;
	}

	/**
	 * Starts a Run put in line: asked now when short, saved waiting for the engine otherwise.
	 *
	 * @param list<string> $chunks
	 */
	private function begin(Run $run, array $chunks): void {
		if ($run->getOffHours()) {
			$this->runs->save($run);
			return;
		}
		$this->steps->ask($run, $this->prompt($run, $chunks, []));
	}

	/**
	 * What a Run's finished steps gave so far.
	 *
	 * @return array{findings: list<IaFinding>, summaries: list<string>}
	 */
	private static function results(Run $run): array {
		return json_decode((string)$run->getResults(), true) ?: ['findings' => [], 'summaries' => []];
	}

	private static function underWay(Run $run): bool {
		return in_array($run->getState(), [Run::WAITING, Run::WORKING], true);
	}

	/**
	 * The prompt of the Run's current step.
	 *
	 * @param list<string> $chunks
	 * @param list<string> $summaries
	 */
	private function prompt(Run $run, array $chunks, array $summaries): string {
		$step = $run->getStep();
		$n = count($chunks);
		[$instruction, $body] = match (true) {
			$run->getSteps() === 1 => [self::SINGLE, $chunks[0]],
			$step <= $n => [sprintf(self::CHUNK, $step, $n), $chunks[$step - 1]],
			default => [sprintf(self::GAPS, $n), implode("\n", array_map(static fn (int $i, string $summary): string => 'Parte ' . ($i + 1) . ': ' . $summary, array_keys($summaries), $summaries))],
		};
		return $instruction . "\n\n<<<\n" . $body . "\n>>>";
	}

	/** @param list<array<string, string>> $lines a chunk step's lines, whose RESUMEN sums it up */
	private static function summary(array $lines): string {
		foreach ($lines as $fields) {
			if (isset($fields['RESUMEN'])) {
				return trim($fields['RESUMEN']);
			}
		}
		return '';
	}

	/** @return list<string> the chunks every step of a Review of $document uses */
	private static function chunks(Document $document): array {
		return ModelGateway::chunks($document->text, mb_strlen(self::SINGLE) > mb_strlen(self::CHUNK) ? self::SINGLE : self::CHUNK);
	}

	/**
	 * $userId's own manual report $id, its Run and the file, while they can open it.
	 *
	 * @return array{0: Report, 1: Run, 2: File}
	 * @throws NotFoundException
	 */
	private function mine(string $userId, int $id): array {
		$report = $this->reports->byId($id);
		$run = $report === null ? null : $this->runs->of(Run::REVIEW, (string)$id);
		$file = $report === null || $report->getRequester() !== $userId ? null : $this->access->file($userId, $report->getFileId());
		if ($run === null || $file === null) {
			throw new NotFoundException();
		}
		return [$report, $run, $file];
	}

	private function notify(Report $report, File $file): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser((string)$report->getRequester())
			->setDateTime($this->time->getDateTime())
			->setObject('review', (string)$report->getId())
			->setSubject('review_ready', ['name' => $file->getName()]);
		$this->notifications->notify($notification);
	}

	/** @return array{review: IaReview, start: 'now'|'offHours'} */
	private function answer(Report $report, Run $run, File $file): array {
		return ['review' => $this->view($report, $run, $file, true), 'start' => $run->getOffHours() ? 'offHours' : 'now'];
	}

	/** @return ($findings is true ? IaReview : IaReviewItem) */
	private function view(Report $report, Run $run, File $file, bool $findings): array {
		$item = [
			'id' => $report->getId(),
			'fileId' => $report->getFileId(),
			'etag' => $report->getEtag(),
			'name' => $file->getName(),
			'state' => $run->getState(),
			'step' => $run->getStep(),
			'steps' => $run->getSteps(),
			'offHours' => (bool)$run->getOffHours(),
			'offHoursWindow' => $this->settings->offHours(),
			'error' => $run->getState() === Run::FAILED ? $run->getLastError() : null,
			'requestedAt' => $report->getCreatedAt(),
		];
		return $findings ? $item + ['findings' => $report->getFindings() === null ? null : json_decode($report->getFindings(), true)] : $item;
	}
}
