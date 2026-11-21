<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Review;

use OCA\Ia\Access\Access;
use OCA\Ia\Action\Actions;
use OCA\Ia\Db\Action;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Report;
use OCA\Ia\Db\ReportMapper;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Db\StaleRun;
use OCA\Ia\Flow\FlowRules;
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
 * - **Automatic** (an Automatic action's or a Flow rule's): a file keeps one automatic report,
 *   reviewed in Off hours on its latest version however often it changes, with its rule's
 *   instructions. An Automatic action's reads the file as whoever added or changed it last;
 *   failing that, as an active member of the action's Editor group who can open it. A Flow rule's
 *   reads it as whoever set the event off. Otherwise the Run fails and nobody is told. Anyone who
 *   can open the file reads it, and whoever added or changed it is told.
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
		private Steps $steps,
		private Actions $actions,
		private FlowRules $flowRules,
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
				$this->begin($run, $report, $this->restart($report, $run, $file, $this->reader->read($file)));
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
		$run->setActor($userId);
		$run->setOrigin(Report::MANUAL);
		$chunks = $this->line($run, $report, $document);
		if (!$this->insert($report, $run)) {
			return $this->create($userId, $fileId);
		}
		if (!$run->getOffHours()) {
			$this->steps->ask($run, $this->prompt($run, $report, $chunks, []));
		}
		return $this->answer($report, $run, $file) + ['created' => true];
	}

	/**
	 * Puts the automatic Review of $file in line for Off hours, for an Automatic action ($origin
	 * action: it was added to, changed in, moved or copied into its folder) or a Flow rule ($origin
	 * flow: a file event matched it), by $changer when known. Only a Review done on this same
	 * version, for this same rule, stays as it is; any other is reviewed from its first step, on the
	 * version current when it starts, with the rule's instructions.
	 *
	 * @param 'action'|'flow' $origin
	 */
	public function automatic(File $file, string $origin, int $rule, string $instructions, ?string $changer): void {
		$report = $this->reports->automaticOf($file->getId()) ?? new Report();
		$run = $report->getId() === null ? new Run() : $this->runs->of(Run::REVIEW, (string)$report->getId());
		if ($run === null || ($run->getState() === Run::DONE && $report->getEtag() === $file->getEtag()
			&& $report->getOrigin() === $origin && $report->getRule() === $rule)) {
			return;
		}
		$report->setFileId($file->getId());
		$report->setAutomaticFile($file->getId());
		$report->setOrigin($origin);
		// No version reviewed: whatever step it was at, the Run starts over (resume, finish).
		$report->setEtag('');
		$report->setRequester($changer);
		$report->setRule($rule);
		$report->setInstructions($instructions === '' ? null : $instructions);
		$report->setCreatedAt($this->time->getTime());
		if ($report->getId() === null) {
			$run->setRule($rule);
			$run->setOrigin($origin);
			$run->setState(Run::WAITING);
			$run->setOffHours(true);
			$run->setStep(0);
			$run->setSteps(0);
			$run->setAttempts(0);
			if (!$this->insert($report, $run)) {
				$this->automatic($file, $origin, $rule, $instructions, $changer);
			}
			return;
		}
		$this->reports->update($report);
		// A Run in line or under way is the engine's to move: the blank version restarts it there.
		if (self::underWay($run)) {
			return;
		}
		$run->setRule($rule);
		$run->setOrigin($origin);
		$run->setState(Run::WAITING);
		$run->setOffHours(true);
		$run->setTaskId(null);
		if (!$this->runs->save($run)) {
			// Another process moved the Run since it was read: line it up again from what it saved.
			$this->automatic($file, $origin, $rule, $instructions, $changer);
		}
	}

	/**
	 * Reviews again the current version of the file a report of $userId's is about.
	 *
	 * @return array{review: IaReview, start: 'now'|'offHours'}
	 * @throws NotFoundException
	 * @throws Refused while it is still under way, or when «IA» can't read its format
	 */
	public function again(string $userId, int $id): array {
		[$report, $run, $file] = $this->seen($userId, $id);
		if ($report->isAutomatic()) {
			throw new NotFoundException();
		}
		if (self::underWay($run)) {
			throw new Refused('Esta revisión sigue en curso.');
		}
		$report->setCreatedAt($this->time->getTime());
		$this->begin($run, $report, $this->restart($report, $run, $file, $this->reader->read($file)));
		return $this->answer($report, $run, $file);
	}

	/**
	 * @return IaReview
	 * @throws NotFoundException
	 */
	public function one(string $userId, int $id): array {
		[$report, $run, $file] = $this->seen($userId, $id);
		return $this->view($report, $run, $file, true);
	}

	/**
	 * @return list<IaReviewItem> $userId's own Reviews and the automatic ones of files they can
	 *                            open, newest first
	 */
	public function all(string $userId): array {
		$items = [];
		foreach ([...$this->reports->requestedBy($userId), ...$this->reports->automatic()] as $report) {
			$file = $this->access->file($userId, $report->getFileId());
			$run = $this->runs->of(Run::REVIEW, (string)$report->getId());
			if ($file !== null && $run !== null) {
				$items[] = $this->view($report, $run, $file, false);
			}
		}
		usort($items, static fn (array $a, array $b): int => [$b['requestedAt'], $b['id']] <=> [$a['requestedAt'], $a['id']]);
		return $items;
	}

	/**
	 * Asks a waiting Review Run's current step, as the run engine decides, from its last finished
	 * step; a Run whose file changed since (an automatic one's first time, too) starts over from
	 * its first step, sized anew. It is the Run's turn, so the step is asked now, unless the file
	 * grew past the short size: then it waits for its turn among the Off-hours Runs.
	 */
	public function resume(Run $run): void {
		$source = $this->source($run);
		if ($source === null) {
			return;
		}
		[$report, $file, $document] = $source;
		$wasOffHours = (bool)$run->getOffHours();
		$chunks = $file->getEtag() !== $report->getEtag() ? $this->restart($report, $run, $file, $document) : self::chunks($document, $report);
		// One that just became an Off-hours Run waits for its turn among them.
		if ($run->getOffHours() && (!$wasOffHours || !$this->settings->isOffHours($this->time->getDateTime()))) {
			$this->runs->save($run);
			return;
		}
		$this->steps->ask($run, $this->prompt($run, $report, $chunks, self::results($run)['summaries']));
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
			$this->begin($run, $report, $this->restart($report, $run, $file, $document));
			return;
		}
		$results = self::results($run);
		$lines = ModelGateway::lines((string)($task->getOutput()['output'] ?? ''));
		$chunks = self::chunks($document, $report);
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
			$this->steps->ask($run, $this->prompt($run, $report, $chunks, $results['summaries']));
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

	/** Erases the reports asked for before $cutoff (an automatic one: lined up), each with its Run and «Revisión lista». */
	public function forgetBefore(int $cutoff): void {
		$ids = $this->atomic(function () use ($cutoff): array {
			$ids = $this->reports->forgetBefore($cutoff);
			$this->runs->forget(Run::REVIEW, $ids);
			return $ids;
		}, $this->db);
		foreach ($ids as $id) {
			$this->notifications->markProcessed($this->notifications->createNotification()->setApp(Application::APP_ID)->setObject('review', (string)$id));
		}
	}

	/** One whose progress was dropped (Run::fail) has its report's version blanked, so that it starts over. */
	public function requeue(Run $run): bool {
		$report = $run->getResults() === null ? $this->reports->byId((int)$run->getTarget()) : null;
		$report?->setEtag('');
		$run->requeue();
		try {
			$this->atomic(function () use ($report, $run): void {
				if (!$this->runs->save($run)) {
					throw new StaleRun();
				}
				if ($report !== null) {
					$this->reports->update($report);
				}
			}, $this->db);
		} catch (StaleRun) {
			return false;
		}
		return true;
	}

	public function about(Run $run): array {
		$name = match ($run->origin()) {
			Report::ACTION => $this->actions->byId((int)$run->getRule())?->getName(),
			Report::FLOW => $this->flowRules->one((int)$run->getRule())['name'] ?? null,
			default => null,
		};
		return ['name' => $name, 'date' => null];
	}

	/**
	 * The Run's report, its file as the Run's actor opens it now, and the Document read from it; a
	 * Run whose file is gone, unreadable or no one may read for it fails, and null comes back. An
	 * Automatic action's Review reads as whoever added or changed the file, or else its Editor group;
	 * a Flow rule's, as whoever set the event off, and never as anyone else.
	 *
	 * @return array{0: Report, 1: File, 2: Document}|null
	 */
	private function source(Run $run): ?array {
		$report = $this->reports->byId((int)$run->getTarget());
		$action = $report?->getOrigin() === Report::ACTION ? $this->actions->byId((int)$report->getRule()) : null;
		$flow = $report?->getOrigin() === Report::FLOW ? $this->flowRules->one((int)$report->getRule()) : null;
		if ($report?->isAutomatic()) {
			// The Run follows its report: an Automatic action takes over a file a Flow rule lined up.
			$run->setOrigin((string)$report->getOrigin());
			$run->setRule($report->getRule());
			$run->setActor(match (true) {
				$flow !== null => $report->getRequester(),
				$action !== null => $this->actor($report, $action),
				default => null,
			});
		}
		$file = $report === null || $run->getActor() === null ? null : $this->access->file($run->getActor(), $report->getFileId());
		try {
			if ($report !== null && $file !== null) {
				return [$report, $file, $this->reader->read($file)];
			}
			$run->fail(match (true) {
				!$report?->isAutomatic() => 'El archivo ya no está disponible.',
				$report->getOrigin() === Report::FLOW && $flow === null => 'Se eliminó la regla de Flujo.',
				$flow !== null && $report->getRequester() === null => 'La regla de Flujo se activó sin una sesión: no hay a nombre de quién leer el archivo.',
				$flow !== null => 'Quien activó la regla de Flujo ya no puede abrir el archivo.',
				$action === null => 'Se eliminó la acción automática.',
				default => 'Ni quien agregó o cambió el archivo ni su grupo editor pueden abrirlo.',
			});
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
		return $this->line($run, $report, $document);
	}

	/**
	 * Puts the Run at the first step of a Review of $document, waiting, unsaved: Off hours for an
	 * automatic one, or a document over the short size, as its own page count says.
	 *
	 * @return list<string> the chunks
	 */
	private function line(Run $run, Report $report, Document $document): array {
		$chunks = self::chunks($document, $report);
		$run->setStep(1);
		$run->setSteps(count($chunks) === 1 ? 1 : count($chunks) + 1);
		$run->setOffHours($report->isAutomatic() || $document->pages > self::SHORT_PAGES);
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
	private function begin(Run $run, Report $report, array $chunks): void {
		if ($run->getOffHours()) {
			$this->runs->save($run);
			return;
		}
		$this->steps->ask($run, $this->prompt($run, $report, $chunks, []));
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
	private function prompt(Run $run, Report $report, array $chunks, array $summaries): string {
		$step = $run->getStep();
		$n = count($chunks);
		[$instruction, $body] = match (true) {
			$run->getSteps() === 1 => [self::SINGLE, $chunks[0]],
			$step <= $n => [sprintf(self::CHUNK, $step, $n), $chunks[$step - 1]],
			default => [sprintf(self::GAPS, $n), implode("\n", array_map(static fn (int $i, string $summary): string => 'Parte ' . ($i + 1) . ': ' . $summary, array_keys($summaries), $summaries))],
		};
		return $instruction . self::told($report) . "\n\n<<<\n" . $body . "\n>>>";
	}

	/** The instructions of the Review's Automatic action or Flow rule, as the prompt adds them; '' when none. */
	private static function told(Report $report): string {
		$instructions = (string)$report->getInstructions();
		return $instructions === '' ? '' : "\nAdemás, se pide: «" . $instructions . '»';
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
	private static function chunks(Document $document, Report $report): array {
		return ModelGateway::chunks($document->text, (mb_strlen(self::SINGLE) > mb_strlen(self::CHUNK) ? self::SINGLE : self::CHUNK) . self::told($report));
	}

	/**
	 * The report $id, its Run and the file, while $userId can open the file: their own manual
	 * report, or an automatic one.
	 *
	 * @return array{0: Report, 1: Run, 2: File}
	 * @throws NotFoundException
	 */
	private function seen(string $userId, int $id): array {
		$report = $this->reports->byId($id);
		$run = $report === null ? null : $this->runs->of(Run::REVIEW, (string)$id);
		$theirs = $report !== null && ($report->isAutomatic() || $report->getRequester() === $userId);
		$file = $theirs ? $this->access->file($userId, $report->getFileId()) : null;
		if ($run === null || $file === null) {
			throw new NotFoundException();
		}
		return [$report, $run, $file];
	}

	/**
	 * Who an automatic Review reads the file as: whoever added or changed it last, while they can
	 * open it; else a member of its action's Editor group who can (Actions::actor).
	 */
	private function actor(Report $report, Action $action): ?string {
		$changer = $report->getRequester();
		return $changer !== null && $this->access->file($changer, $report->getFileId()) !== null
			? $changer
			: $this->actions->actor($action, $report->getFileId());
	}

	/** Puts a new report and its Review Run in the database together; false when the report was already there. */
	private function insert(Report $report, Run $run): bool {
		$run->setKind(Run::REVIEW);
		$run->setVersion(0);
		$run->setUpdatedAt($this->time->getTime());
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
			return false;
		}
		return true;
	}

	/** Tells who asked for a report, or who added or changed the file of an automatic one while they can open it. */
	private function notify(Report $report, File $file): void {
		$to = $report->getRequester();
		if ($to === null || ($report->isAutomatic() && $this->access->file($to, $report->getFileId()) === null)) {
			return;
		}
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($to)
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
			'origin' => $report->getOrigin(),
			'instructions' => $report->getInstructions(),
		];
		return $findings ? $item + ['findings' => $report->getFindings() === null ? null : json_decode($report->getFindings(), true)] : $item;
	}
}
