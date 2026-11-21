<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Bulletin;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Ia\Access\Access;
use OCA\Ia\AppInfo\Application;
use OCA\Ia\Db\Bulletin;
use OCA\Ia\Db\BulletinMapper;
use OCA\Ia\Db\Changes;
use OCA\Ia\Db\Edition;
use OCA\Ia\Db\EditionMapper;
use OCA\Ia\Db\Reading;
use OCA\Ia\Db\Run;
use OCA\Ia\Db\RunMapper;
use OCA\Ia\Db\StaleRun;
use OCA\Ia\Model\ModelGateway;
use OCA\Ia\Reading\Document;
use OCA\Ia\Reading\Readings;
use OCA\Ia\Reading\Scanned;
use OCA\Ia\Reading\TextReader;
use OCA\Ia\Refused;
use OCA\Ia\Run\Kind;
use OCA\Ia\Run\Steps;
use OCA\Ia\Settings\Settings;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Task;

/**
 * An Edition's Run (gestion-aio#162, Bulletins and Readings): on its date, in Off hours, it
 * writes the Edition and publishes it whole; its Readers are told.
 *
 * - **When.** On the latest date due, from the Bulletin's next date on (its creation's Off hours,
 *   then the night after each Edition is published): after missed dates one Edition, not one per
 *   date, and never a second while one is still being written. A Bulletin that comes «cuando
 *   cambien sus fuentes» gets one only after a Source's etag or sync token changed from the state
 *   its Editions are up to. An Edition still unpublished once the Off hours of its date are over
 *   is «atrasada»: its Editors are told once, and it continues the next night.
 *
 * 1. **Survey**, without the Model: the Run acts as an active member of the Editor group who can
 *    open every Source, lists the Sources' documents (a document Source whatever its format, so
 *    one «IA» can't read is listed as such) and keeps each one's Reading when it is of the same
 *    version. A calendar is only access-checked: its meetings come with the Personal list
 *    (APS-Conecta/ia#12). A Source gone or unreadable is told to the Editors.
 * 2. **Readings**: each document missing one is read, part by part when long; one that changed
 *    since the survey is read in its new version, from its first part.
 * 3. **The Resumen**, from the Readings, in context-sized rounds until one is left; none when no
 *    document could be read.
 * 4. **Publish**, with each document's version, whether it is new or changed, and its Reading.
 *
 * Its progress is the Run's results, so it resumes from its last finished step, and its step i of
 * n the asks planned; an empty answer is a failed attempt.
 */
class EditionRun implements Kind {
	use TTransactional;

	private const RESUMEN = 'Escribe el resumen del boletín «%s» para sus lectores de un centro de salud, a partir de las lecturas de sus documentos que están entre las marcas <<< y >>>. '
		. 'En uno o dos párrafos breves: qué documentos son nuevos o cambiaron y lo más importante de ellos. No agregues nada que no esté en las lecturas. ' . ModelGateway::CLOSE;
	private const ROUND = 'Resume en un párrafo estas lecturas de documentos de un centro de salud, que están entre las marcas <<< y >>>: qué es nuevo o cambió y lo más importante. ' . ModelGateway::CLOSE;

	public function __construct(
		private BulletinMapper $bulletins,
		private EditionMapper $editionMapper,
		private RunMapper $runs,
		private Bulletins $setUp,
		private Editions $editions,
		private Readings $readings,
		private TextReader $reader,
		private ModelGateway $model,
		private Steps $steps,
		private Access $access,
		private Settings $settings,
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private IDBConnection $db,
		private Changes $changes,
	) {
	}

	/**
	 * In Off hours: an Edition, and its Run in line, for each Bulletin due since its previous Edition,
	 * or whose Sources changed, as of the date these Off hours end.
	 */
	public function due(DateTimeInterface $now): void {
		$today = new DateTimeImmutable($this->settings->offHoursDate($now));
		foreach ($this->bulletins->all() as $bulletin) {
			if ($today->format('Y-m-d') < $bulletin->getNext() || $this->setUp->writing($bulletin) !== null) {
				continue;
			}
			$schedule = Schedule::parse($bulletin->getSchedule());
			$date = $schedule->last($today);
			if ($schedule->kind === 'sources' && $this->changes->of($this->setUp->stored($bulletin)) !== $bulletin->getFingerprint()) {
				$date = $today->format('Y-m-d');
			}
			$previous = $this->editionMapper->ofBulletin($bulletin->getId())[0] ?? null;
			if ($date !== null && $date >= $bulletin->getNext() && ($previous === null || $date > $previous->getDate())) {
				$this->open($bulletin, $date, $schedule);
			}
		}
	}

	/**
	 * Each Edition still being written once the Off hours of its date are over is «atrasada», and
	 * its Editors are told, once.
	 */
	public function overdue(DateTimeInterface $now): void {
		$today = $this->settings->offHoursDate($now);
		foreach ($this->runs->inState(Run::WAITING, Run::WORKING) as $run) {
			$edition = $run->getKind() === Run::EDITION ? $this->editionMapper->byId((int)$run->getTarget()) : null;
			$bulletin = $edition === null ? null : $this->bulletins->byId($edition->getBulletinId());
			if ($bulletin === null || $edition->getDate() >= $today || $edition->getLate()) {
				continue;
			}
			$edition->setLate(true);
			$this->editionMapper->update($edition);
			$this->tell($this->editorsOf($bulletin), 'edition_late', $bulletin, $edition);
		}
	}

	public function resume(Run $run): void {
		[$bulletin, $edition] = $this->target($run) ?? [null, null];
		if ($bulletin === null) {
			return;
		}
		$state = self::state($run) ?? $this->survey($run, $bulletin, $edition);
		if ($state !== null) {
			$this->next($run, $bulletin, $edition, $state);
		}
	}

	public function finish(Run $run, Task $task, ?string $error): void {
		$this->model->forget($task);
		if ($run->getTaskId() !== $task->getId() || $run->getState() !== Run::WORKING) {
			return;
		}
		[$bulletin, $edition] = $this->target($run) ?? [null, null];
		if ($bulletin === null) {
			return;
		}
		$state = self::state($run);
		if ($error !== null || $state === null || !$this->take($run, $state, (string)($task->getOutput()['output'] ?? ''))) {
			$run->retry($error ?? 'El modelo respondió vacío.');
			$this->runs->save($run);
			return;
		}
		$run->setAttempts(0);
		$run->setStep($run->getStep() + 1);
		if (!$this->settings->isOffHours($this->time->getDateTime())) {
			$run->setResults(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
			$run->setTaskId(null);
			$run->setState(Run::WAITING);
			$this->runs->save($run);
			return;
		}
		$this->next($run, $bulletin, $edition, $state);
	}

	/**
	 * One whose survey never finished surveys again; any other goes on from its last finished step.
	 * One a newer Edition of its Bulletin has passed stays failed: missed dates make one Edition.
	 */
	public function requeue(Run $run): bool {
		$edition = $this->editionMapper->byId((int)$run->getTarget());
		$latest = $edition === null ? null : ($this->editionMapper->ofBulletin($edition->getBulletinId())[0] ?? null);
		if ($latest !== null && $latest->getId() !== $edition->getId()) {
			throw new Refused('Este boletín ya tiene una edición más reciente.');
		}
		$run->requeue();
		return $this->runs->save($run);
	}

	public function about(Run $run): array {
		$edition = $this->editionMapper->byId((int)$run->getTarget());
		$bulletin = $edition === null ? null : $this->bulletins->byId($edition->getBulletinId());
		return ['name' => $bulletin?->getName(), 'date' => $bulletin === null ? null : $edition?->getDate()];
	}

	private function open(Bulletin $bulletin, string $date, Schedule $schedule): void {
		$edition = new Edition();
		$edition->setBulletinId($bulletin->getId());
		$edition->setDate($date);
		$edition->setUntil($schedule->until(new DateTimeImmutable($date)));
		$edition->setLate(false);
		$edition->setStatus(Edition::WRITING);
		$run = new Run();
		$run->setKind(Run::EDITION);
		$run->setState(Run::WAITING);
		$run->setOffHours(true);
		$run->setStep(0);
		$run->setSteps(0);
		$run->setAttempts(0);
		$run->setVersion(0);
		$run->setUpdatedAt($this->time->getTime());
		try {
			$this->atomic(function () use ($edition, $run): void {
				$this->editionMapper->insert($edition);
				$run->setTarget((string)$edition->getId());
				$this->runs->insert($run);
			}, $this->db);
		} catch (Exception $e) {
			// Another tick opened it first.
			if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/**
	 * The Run's Bulletin and Edition; when either is gone the Run fails and null comes back.
	 *
	 * @return array{0: Bulletin, 1: Edition}|null
	 */
	private function target(Run $run): ?array {
		$edition = $this->editionMapper->byId((int)$run->getTarget());
		$bulletin = $edition === null ? null : $this->bulletins->byId($edition->getBulletinId());
		if ($edition === null || $bulletin === null) {
			$run->fail('El boletín ya no existe.');
			$this->runs->save($run);
			return null;
		}
		return [$bulletin, $edition];
	}

	/**
	 * Step 1, without the Model. Null when no member of the Editor group can open every Source,
	 * and the Run fails.
	 *
	 * @return array<string, mixed>|null
	 */
	private function survey(Run $run, Bulletin $bulletin, Edition $edition): ?array {
		$actor = $this->setUp->actor($bulletin);
		if ($actor === null) {
			// It fails into «Estado» (APS-Conecta/ia#8), and nobody is told.
			$run->fail('Nadie del grupo editor puede abrir todas las fuentes del boletín.');
			$this->runs->save($run);
			return null;
		}
		$run->setActor($actor->getUID());
		$stored = $this->setUp->stored($bulletin);
		// What its Sources are before they are read: the state this Edition brings its Bulletin up to.
		$fingerprint = $this->changes->of($stored);
		$files = [];
		$found = [];
		$documentSources = [];
		foreach ($stored as $source) {
			if ($source['type'] === 'calendar') {
				continue;
			}
			$node = $this->access->node($actor->getUID(), (int)$source['id'], $source['type']);
			if ($node === null) {
				// Gone since it was set up (Bulletins::actor skips it): told once to each Editor.
				$this->tell($this->editorsOf($bulletin), 'source_gone', $bulletin, $edition, ['source', $bulletin->getId() . ':' . $source['type'] . ':' . $source['id']]);
			} elseif ($source['type'] === 'file') {
				$documentSources[$node->getId()] = true;
			}
			foreach (self::documents($node) as $file) {
				$files[$file->getId()] = ['fileId' => $file->getId(), 'etag' => $file->getEtag(), 'name' => $file->getName()];
				$found[$file->getId()] = $file;
			}
		}
		$previous = $this->editions->previous($edition);
		$queue = [];
		$asks = 0;
		foreach ($files as $id => $entry) {
			$reading = $this->readings->of($id, $entry['etag']);
			if ($reading === null) {
				$document = $this->readable($found[$id], $entry['etag']);
				if ($document !== null) {
					$queue[] = $id;
					$asks += self::asks(count($this->readings->parts($document)));
					continue;
				}
			}
			// A document Source this version of which can't be read, whoever tried it: told when the
			// version is new to the Bulletin.
			if (isset($documentSources[$id]) && $reading?->getStatus() !== Reading::READ && ($previous[$id] ?? null) !== $entry['etag']) {
				$this->tell($this->editorsOf($bulletin), 'source_unreadable', $bulletin, $edition, null, ['source' => $entry['name']]);
			}
		}
		// The Readings to take, then a Resumen of one ask at least.
		$run->setStep(1);
		$run->setSteps($asks + 1);
		return [
			'files' => array_values($files), 'queue' => $queue, 'part' => 0, 'parts' => [], 'batches' => null, 'partials' => [], 'resumen' => null,
			'fingerprint' => $fingerprint,
		];
	}

	/** @return list<string> the Bulletin's Editors: its Editor group's active members */
	private function editorsOf(Bulletin $bulletin): array {
		return array_map(static fn (IUser $user): string => $user->getUID(), $this->access->members($bulletin->getEditorGroup()));
	}

	/**
	 * Notifies these people of an Edition, its Bulletin named; about $object (type and id) rather
	 * than the Edition when given, then only those not already told of it.
	 *
	 * @param iterable<string> $uids
	 * @param array{0: string, 1: string}|null $object
	 * @param array<string, string> $more further subject parameters
	 */
	private function tell(iterable $uids, string $subject, Bulletin $bulletin, Edition $edition, ?array $object = null, array $more = []): void {
		[$type, $id] = $object ?? ['edition', (string)$edition->getId()];
		foreach ($uids as $uid) {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)->setUser($uid)->setObject($type, $id);
			if ($object !== null && $this->notifications->getCount($notification) > 0) {
				continue;
			}
			$notification->setDateTime($this->time->getDateTime())
				->setSubject($subject, ['name' => $bulletin->getName(), 'bulletin' => $bulletin->getId()] + $more);
			$this->notifications->notify($notification);
		}
	}

	/**
	 * Asks the next step, or publishes once the Resumen is written.
	 *
	 * @param array<string, mixed> $state
	 */
	private function next(Run $run, Bulletin $bulletin, Edition $edition, array $state): void {
		$prompt = $this->prompt($run, $bulletin, $edition, $state);
		if ($prompt === null) {
			$this->publish($run, $bulletin, $edition, $state);
			return;
		}
		$run->setResults(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$this->steps->ask($run, $prompt);
	}

	/**
	 * The next ask: the first queued document's next part, or its parts' merge; then the
	 * Resumen's rounds. Null once the Resumen is written, or when there is nothing to write it from.
	 *
	 * @param array<string, mixed> $state
	 */
	private function prompt(Run $run, Bulletin $bulletin, Edition $edition, array &$state): ?string {
		while ($state['queue'] !== []) {
			$document = $this->queued($run, $state);
			if ($document !== null) {
				$chunks = $this->readings->parts($document);
				$part = $state['part'];
				return $part < count($chunks)
					? $this->readings->prompt($part + 1, count($chunks), $chunks[$part])
					: $this->readings->mergePrompt(array_column($state['parts'], 'summary'));
			}
		}
		$resumen = sprintf(self::RESUMEN, $bulletin->getName());
		if ($state['batches'] === null) {
			$digest = $this->digest($edition, $state['files']);
			$state['batches'] = $digest === '' ? [] : ModelGateway::chunks($digest, $resumen);
			self::plan($run, $state['batches']);
		}
		while ($state['resumen'] === null && $state['batches'] !== []) {
			$batches = $state['batches'];
			$done = count($state['partials']);
			if (count($batches) === 1) {
				return $resumen . "\n\n<<<\n" . $batches[0] . "\n>>>";
			}
			if ($done < count($batches)) {
				return self::ROUND . "\n\n<<<\n" . $batches[$done] . "\n>>>";
			}
			// Each round's paragraphs are the next round's input, until one batch is left.
			$state['batches'] = ModelGateway::chunks(implode("\n\n", $state['partials']), $resumen);
			$state['partials'] = [];
			self::plan($run, $state['batches']);
		}
		return null;
	}

	/**
	 * Takes the answer to the step asked. False when it is empty.
	 *
	 * @param array<string, mixed> $state
	 */
	private function take(Run $run, array &$state, string $answer): bool {
		if ($state['queue'] === []) {
			$text = ModelGateway::prose($answer);
			if ($text === '') {
				return false;
			}
			if (count($state['batches']) === 1) {
				$state['resumen'] = $text;
			} else {
				$state['partials'][] = $text;
			}
			return true;
		}
		$id = $state['queue'][0];
		$asked = self::entry($state, $id)['etag'];
		$document = $this->queued($run, $state);
		if ($document === null || self::entry($state, $id)['etag'] !== $asked) {
			// Gone, unreadable now, or changed since the ask: the answer is of no version to keep.
			return true;
		}
		$parts = count($this->readings->parts($document));
		if ($state['part'] < $parts) {
			$part = $this->readings->part($answer, $document);
			if ($part['summary'] === '') {
				return false;
			}
			$state['parts'][] = $part;
			$state['part']++;
			if ($parts > 1) {
				return true;
			}
			$summary = $part['summary'];
		} else {
			$summary = $this->readings->merged($answer);
			if ($summary === '') {
				return false;
			}
		}
		$found = array_values(array_filter(array_column($state['parts'], 'expires')));
		$this->readings->store($id, self::entry($state, $id)['etag'], Reading::READ, $summary, $found[0] ?? null,
			array_values(array_unique(array_merge(...array_column($state['parts'], 'cargos')))));
		self::dequeue($state);
		return true;
	}

	/**
	 * The first queued document's text now. One that changed since the survey is listed in its new
	 * version, from its first part. When it is gone, or can't be read any more, it leaves the queue
	 * (a gone one leaves the Edition too) and null comes back.
	 *
	 * @param array<string, mixed> $state
	 */
	private function queued(Run $run, array &$state): ?Document {
		$id = $state['queue'][0];
		$file = $this->access->file((string)$run->getActor(), $id);
		if ($file !== null && $file->getEtag() !== self::entry($state, $id)['etag']) {
			$state['files'] = array_map(static fn (array $entry): array => $entry['fileId'] === $id
				? ['fileId' => $id, 'etag' => $file->getEtag(), 'name' => $file->getName()]
				: $entry, $state['files']);
			$state['part'] = 0;
			$state['parts'] = [];
		}
		$document = $file === null ? null : $this->readable($file, $file->getEtag());
		if ($document === null) {
			self::dequeue($state);
			if ($file === null) {
				$state['files'] = array_values(array_filter($state['files'], static fn (array $entry): bool => $entry['fileId'] !== $id));
			}
		}
		return $document;
	}

	/** The file's text; a scan or a file «IA» can't read gets its Reading at once, as version $etag, and null comes back. */
	private function readable(File $file, string $etag): ?Document {
		try {
			return $this->reader->read($file);
		} catch (Scanned) {
			$this->readings->store($file->getId(), $etag, Reading::SCAN);
		} catch (Refused) {
			$this->readings->store($file->getId(), $etag, Reading::FAILED);
		}
		return null;
	}

	/**
	 * The Readings the Resumen is written from, one line each, marked new, changed or unchanged.
	 *
	 * @param list<array{fileId: int, etag: string, name: string}> $files
	 */
	private function digest(Edition $edition, array $files): string {
		$previous = $this->editions->previous($edition);
		$lines = [];
		foreach ($files as $entry) {
			$reading = $this->readings->of($entry['fileId'], $entry['etag']);
			if ($reading?->getStatus() === Reading::READ) {
				$change = match ($previous[$entry['fileId']] ?? null) {
					null => 'nuevo',
					$entry['etag'] => 'sin cambios',
					default => 'cambiado',
				};
				$lines[] = "- {$entry['name']} ($change): " . $reading->getSummary();
			}
		}
		return implode("\n", $lines);
	}

	/**
	 * Publishes the Edition whole and tells the Readers who can see it.
	 *
	 * @param array<string, mixed> $state
	 */
	private function publish(Run $run, Bulletin $bulletin, Edition $edition, array $state): void {
		$previous = $this->editions->previous($edition);
		$documents = [];
		foreach ($state['files'] as $entry) {
			$reading = $this->readings->of($entry['fileId'], $entry['etag']);
			$documents[] = $entry + [
				'new' => ($previous[$entry['fileId']] ?? null) !== $entry['etag'],
				'status' => $reading?->getStatus() ?? Reading::FAILED,
				'expires' => $reading?->getExpires(),
				'cargos' => $reading?->cargoList() ?? [],
			];
		}
		// Its period, for one that comes «cuando cambien sus fuentes», ended the previous one's.
		$earlier = Schedule::parse($bulletin->getSchedule())->kind === 'sources' ? $this->editions->before($edition) : null;
		$earlier?->setUntil($edition->getDate());
		$edition->setResumen($state['resumen']);
		$edition->setFiles(json_encode($documents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
		$edition->setStatus(Edition::PUBLISHED);
		$edition->setPublishedAt($this->time->getTime());
		// Its next Edition comes from the night after on; the Sources' state is caught up.
		$bulletin->setNext((new DateTimeImmutable($this->settings->offHoursDate($this->time->getDateTime())))->modify('+1 day')->format('Y-m-d'));
		$bulletin->setFingerprint($state['fingerprint'] ?? $bulletin->getFingerprint());
		$run->setState(Run::DONE);
		$run->setTaskId(null);
		$run->setResults(null);
		try {
			$this->atomic(function () use ($run, $edition, $earlier, $bulletin): void {
				if (!$this->runs->save($run)) {
					throw new StaleRun();
				}
				$this->editionMapper->update($edition);
				if ($earlier !== null) {
					$this->editionMapper->update($earlier);
				}
				$this->bulletins->update($bulletin);
			}, $this->db);
		} catch (StaleRun) {
			return;
		}
		$this->tell($this->editions->readers($bulletin, $edition), 'edition_published', $bulletin, $edition);
	}

	/**
	 * Its steps, now that the Resumen's rounds are known: those taken, then a round's asks (one
	 * batch is the Resumen; more are a paragraph each and the Resumen of them).
	 *
	 * @param list<string> $batches
	 */
	private static function plan(Run $run, array $batches): void {
		$run->setSteps($run->getStep() - 1 + self::asks(count($batches)));
	}

	/** The asks $pieces take: one for a single piece; one each, and one more to merge them, for several. */
	private static function asks(int $pieces): int {
		return $pieces <= 1 ? $pieces : $pieces + 1;
	}

	/** @return array<string, mixed>|null the Run's progress, null before its survey */
	private static function state(Run $run): ?array {
		return json_decode((string)$run->getResults(), true) ?: null;
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array{fileId: int, etag: string, name: string}
	 */
	private static function entry(array $state, int $id): array {
		return array_values(array_filter($state['files'], static fn (array $entry): bool => $entry['fileId'] === $id))[0];
	}

	/** @param array<string, mixed> $state */
	private static function dequeue(array &$state): void {
		array_shift($state['queue']);
		$state['part'] = 0;
		$state['parts'] = [];
	}

	/**
	 * A Source's documents: a document Source itself, whatever its format; under a folder Source,
	 * at any depth, those of the formats «IA» reads.
	 *
	 * @return list<File>
	 */
	private static function documents(?Node $node): array {
		if ($node instanceof File) {
			return [$node];
		}
		$found = [];
		foreach ($node instanceof Folder ? $node->getDirectoryListing() : [] as $child) {
			if ($child instanceof Folder) {
				array_push($found, ...self::documents($child));
			} elseif ($child instanceof File && TextReader::reads($child->getName())) {
				$found[] = $child;
			}
		}
		return $found;
	}
}
