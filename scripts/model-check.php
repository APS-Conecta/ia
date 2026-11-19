<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * The harness's proof that the Model answers, run inside the instance's Nextcloud container
 * before the seam suite (make model-check): Ollama holds the Model at the pinned digest, and one
 * core:text2text task scheduled through TaskProcessing's own OCS API comes back successful from
 * the standard connector, through AIO's worker. The task is deleted afterwards.
 *
 * Usage: php model-check.php <model> <digest>   (the dev instance's admin / admin)
 */

[, $model, $digest] = $argv + [null, null, null];
if ($model === null || $digest === null) {
	fwrite(STDERR, "usage: model-check.php <model> <digest>\n");
	exit(2);
}

const WAIT = 15 * 60; // seconds: a cold CPU load of the Model plus one short answer

/** @return array{int, mixed} the status and the decoded body */
function call(string $method, string $url, ?array $body = null): array {
	$curl = curl_init($url);
	$headers = ['OCS-APIRequest: true', 'Accept: application/json'];
	if ($body !== null) {
		$headers[] = 'Content-Type: application/json';
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
	}
	curl_setopt_array($curl, [
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_HTTPHEADER => $headers,
	]);
	if (str_starts_with($url, 'http://localhost')) {
		curl_setopt($curl, CURLOPT_USERPWD, 'admin:admin');
	}
	$text = curl_exec($curl);
	if ($text === false) {
		fail("$method $url: " . curl_error($curl));
	}
	return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode((string)$text, true)];
}

function fail(string $why): never {
	fwrite(STDERR, "model-check: $why\n");
	exit(1);
}

[, $tags] = call('GET', 'http://ollama:11434/api/tags');
$held = [];
foreach ($tags['models'] ?? [] as $entry) {
	$held[$entry['name']] = 'sha256:' . $entry['digest'];
}
if (($held[$model] ?? null) !== $digest) {
	fail("Ollama holds $model at " . ($held[$model] ?? 'no digest') . ", not the pinned $digest");
}
echo "Ollama holds $model at the pinned digest.\n";

$ocs = 'http://localhost/ocs/v2.php/taskprocessing';
[$status, $scheduled] = call('POST', "$ocs/schedule", [
	'type' => 'core:text2text',
	'appId' => 'ia-harness',
	'input' => ['input' => 'Responde solo con la palabra: listo'],
]);
$task = $scheduled['ocs']['data']['task'] ?? null;
if ($status !== 200 || $task === null) {
	fail("scheduling a core:text2text task answered $status: " . json_encode($scheduled));
}

$deadline = time() + WAIT;
do {
	sleep(5);
	[, $read] = call('GET', "$ocs/task/{$task['id']}");
	$task = $read['ocs']['data']['task'] ?? $task;
} while (in_array($task['status'], ['STATUS_SCHEDULED', 'STATUS_RUNNING'], true) && time() < $deadline);

call('DELETE', "$ocs/task/{$task['id']}");
if ($task['status'] !== 'STATUS_SUCCESSFUL') {
	fail("the task ended {$task['status']}: " . json_encode($task['errorMessage'] ?? null));
}
echo 'The Model answered through TaskProcessing: ' . trim((string)$task['output']['output']) . "\n";
