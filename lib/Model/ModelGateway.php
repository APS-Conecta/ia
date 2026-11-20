<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Model;

use OCA\Ia\AppInfo\Application;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * The only caller of TaskProcessing (gestion-aio#162, ModelGateway; gestion-aio ADR 0003). Every
 * call is a scheduled `core:text2text` task with no user and the Run's id as its custom id, as
 * Estadística's nota asks; its result comes back to TaskListener. Never `runTask` here: a web
 * request must not wait on the Model.
 */
class ModelGateway {
	/** The Model's context, as the suite's Ollama serves it (OLLAMA_CONTEXT_LENGTH). */
	public const CONTEXT_TOKENS = 8192;
	/** The answer's ceiling, as the suite sets the connector's `max_tokens`. */
	public const MAX_TOKENS = 1000;
	/** Characters per token, counted low for Spanish so that a chunk never overflows. */
	private const CHARS_PER_TOKEN = 3;
	/** The line a prompt asks the Model to end on: without it, the last line is unfinished. */
	public const END = 'FIN';
	/** How every prompt asks for the END line. */
	public const CLOSE = 'Al terminar, escribe una última línea con la palabra ' . self::END . '.';

	public function __construct(
		private IManager $taskProcessing,
	) {
	}

	/** Asks the Model $prompt for the Run $runId; answers the task's id. */
	public function ask(int $runId, string $prompt): int {
		$task = new Task(TextToText::ID, ['input' => $prompt], Application::APP_ID, null, (string)$runId);
		$this->taskProcessing->scheduleTask($task);
		return (int)$task->getId();
	}

	/** Deletes a task once its result is stored, or once it no longer matters. */
	public function forget(Task $task): void {
		$this->taskProcessing->deleteTask($task);
	}

	/** The task $taskId as TaskProcessing holds it now, or null when it holds none. */
	public function task(int $taskId): ?Task {
		try {
			return $this->taskProcessing->getTask($taskId);
		} catch (NotFoundException) {
			return null;
		}
	}

	/** Cancels a stuck task and deletes it: a late answer to it is never taken. */
	public function cancel(Task $task): void {
		$this->taskProcessing->cancelTask((int)$task->getId());
		$this->forget($task);
	}

	/**
	 * $text in chunks that each fit the context beside $instruction and the answer: whole lines
	 * while they fit, a line longer than a chunk cut at its last space.
	 *
	 * @return list<string>
	 */
	public static function chunks(string $text, string $instruction): array {
		$room = (self::CONTEXT_TOKENS - self::MAX_TOKENS) * self::CHARS_PER_TOKEN - mb_strlen($instruction);
		$pieces = [];
		foreach (explode("\n", $text) as $line) {
			while (mb_strlen($line) > $room) {
				$cut = mb_strrpos(mb_substr($line, 0, $room), ' ') ?: $room;
				$pieces[] = mb_substr($line, 0, $cut);
				$line = ltrim(mb_substr($line, $cut));
			}
			$pieces[] = $line;
		}
		$chunks = [];
		$chunk = null;
		foreach ($pieces as $piece) {
			if ($chunk !== null && mb_strlen($chunk) + 1 + mb_strlen($piece) > $room) {
				$chunks[] = $chunk;
				$chunk = null;
			}
			$chunk = $chunk === null ? $piece : "$chunk\n$piece";
		}
		$chunks[] = $chunk ?? '';
		return $chunks;
	}

	/**
	 * The Model's line-formatted answer, read leniently: one map per line of `KEY: value` fields
	 * split by `|`, keys upper-cased without accents, list marks dropped. What follows the END
	 * line is ignored; an answer without it was cut, so its last line is dropped.
	 *
	 * @return list<array<string, string>>
	 */
	public static function lines(string $answer): array {
		$lines = self::upToEnd(array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $answer))), static fn (string $line): bool => $line !== '')));
		$maps = [];
		foreach ($lines as $line) {
			$fields = [];
			foreach (explode('|', (string)preg_replace('/^(?:[-*•]|\d+[.)])\s*/u', '', $line)) as $part) {
				if (preg_match('/^\s*\**\s*([\p{L} ]+?)\s*\**\s*:\s*(.*)$/u', $part, $field) === 1) {
					$fields[self::fold($field[1])] = trim($field[2], " \t*");
				}
			}
			if ($fields !== []) {
				$maps[] = $fields;
			}
		}
		return $maps;
	}

	/**
	 * The Model's prose answer: its text up to the END line; an answer without it was cut, so its
	 * last line is dropped.
	 */
	public static function prose(string $answer): string {
		return trim(implode("\n", self::upToEnd(explode("\n", trim(str_replace("\r", '', $answer))))));
	}

	/**
	 * @param list<string> $lines an answer's lines
	 * @return list<string> those before the END line; all but the last when there is none, as the answer was cut
	 */
	private static function upToEnd(array $lines): array {
		$end = array_search(self::END, array_map(static fn (string $line): string => strtoupper(trim($line, " *.#")), $lines), true);
		return $end === false ? array_slice($lines, 0, -1) : array_slice($lines, 0, $end);
	}

	/** $value upper-cased, without accents or surrounding marks, its spaces collapsed: how a key or a kind is matched. */
	public static function fold(string $value): string {
		return (string)preg_replace('/\s+/u', ' ', strtr(mb_strtoupper(trim($value, " \t.*")), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
	}
}
