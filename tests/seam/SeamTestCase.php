<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The seam (gestion-aio#162, Testing Decisions): a test sees «IA» as a person or an operator
 * does, through its OCS API and occ, inside the throwaway instance's Nextcloud container, and
 * puts a person's files in place through WebDAV as their own client would. Nothing here loads
 * an app class. Every person a test needs is a fresh account, removed when its class ends.
 */
abstract class SeamTestCase extends TestCase {
	private const BASE = 'http://localhost/ocs/v2.php';
	private const DAV = 'http://localhost/remote.php/dav/files/';
	private const OCC = '/var/www/html/occ';
	private const LOGS = '/var/www/html/data/*.log';

	/** @var array<string, string> each account this class made, with its password */
	private static array $people = [];
	/** @var list<string> each group this class made */
	private static array $groups = [];

	public static function tearDownAfterClass(): void {
		foreach (array_keys(self::$people) as $name) {
			self::occ('user:delete', $name);
		}
		foreach (self::$groups as $group) {
			self::occ('group:delete', $group);
		}
		self::$people = [];
		self::$groups = [];
	}

	/** A fresh account, unique to this run, and its login name. */
	protected static function person(): string {
		$name = 'person-' . bin2hex(random_bytes(4));
		$password = bin2hex(random_bytes(16));
		self::occWithEnv(['OC_PASS' => $password], 'user:add', '--password-from-env', $name);
		self::$people[$name] = $password;
		return $name;
	}

	/** A fresh account that is a Nextcloud admin. */
	protected static function admin(): string {
		$name = self::person();
		self::occ('group:adduser', 'admin', $name);
		return $name;
	}

	/** A fresh group with $members, unique to this run, and its id. */
	protected static function group(string ...$members): string {
		$group = 'group-' . bin2hex(random_bytes(4));
		self::occ('group:add', $group);
		foreach ($members as $member) {
			self::occ('group:adduser', $group, $member);
		}
		self::$groups[] = $group;
		return $group;
	}

	/**
	 * Sets Off hours, through the admin setting, to a window from an hour ago to three hours from
	 * now in the server's timezone, so that they cover the test run; the Editor groups stay.
	 *
	 * @return array{start: string, end: string}
	 */
	protected static function offHoursNow(): array {
		$admin = self::admin();
		$settings = self::ocs($admin, 'GET', '/apps/ia/api/v1/admin/settings')['data'];
		$now = new \DateTimeImmutable('now', new \DateTimeZone($settings['timezone']));
		$hours = ['start' => $now->modify('-1 hour')->format('H:i'), 'end' => $now->modify('+3 hours')->format('H:i')];
		$answer = self::ocs($admin, 'PUT', '/apps/ia/api/v1/admin/settings', [
			'editorGroups' => array_column($settings['editorGroups'], 'id'),
			'offHours' => $hours,
		]);
		self::assertSame(200, $answer['status']);
		return $hours;
	}

	/** occ as the web server's user; a failing command fails the test with its output. */
	protected static function occ(string ...$args): string {
		return self::occWithEnv([], ...$args);
	}

	/** @param array<string, string> $env extra environment for occ */
	private static function occWithEnv(array $env, string ...$args): string {
		$process = proc_open(['php', self::OCC, '--no-interaction', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
		if ($process === false) {
			throw new RuntimeException('occ did not start');
		}
		$out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
		$code = proc_close($process);
		if ($code !== 0) {
			throw new RuntimeException('occ ' . implode(' ', $args) . " exited $code: $out");
		}
		return $out;
	}

	/**
	 * An OCS call as the person (null: no credentials), with an optional JSON body, answered as
	 * {status, data}.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array{status: int, data: mixed}
	 */
	protected static function ocs(?string $person, string $method, string $path, ?array $body = null): array {
		$headers = ['OCS-APIRequest: true', 'Accept: application/json'];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		[$status, $text] = self::http($person, $method, self::BASE . $path, $headers, $body === null ? null : json_encode($body));
		$json = json_decode($text, true);
		return ['status' => $status, 'data' => is_array($json) ? ($json['ocs']['data'] ?? null) : null];
	}

	/** Puts $content in the person's Files as $name, as their client would, and answers its file id. */
	protected static function upload(string $person, string $name, string $content): int {
		[$status] = self::http($person, 'PUT', self::DAV . $person . '/' . rawurlencode($name), [], $content);
		self::assertContains($status, [201, 204], "PUT $name answered $status");
		return self::file($person, $name)['fileId'];
	}

	/**
	 * The file's id and etag as the person's client reads them.
	 *
	 * @return array{fileId: int, etag: string}
	 */
	protected static function file(string $person, string $name): array {
		$query = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			. '<d:prop><d:getetag/><oc:fileid/></d:prop></d:propfind>';
		[$status, $text] = self::http($person, 'PROPFIND', self::DAV . $person . '/' . rawurlencode($name), ['Depth: 0'], $query);
		self::assertSame(207, $status, "PROPFIND $name answered $status");
		preg_match('~<oc:fileid>(\d+)</oc:fileid>~', $text, $id);
		preg_match('~<d:getetag>&quot;?([^<&]+)&quot;?</d:getetag>~', $text, $etag);
		return ['fileId' => (int)($id[1] ?? 0), 'etag' => $etag[1] ?? ''];
	}

	/** Moves the person's file to their trash, so they can no longer open it. */
	protected static function remove(string $person, string $name): void {
		[$status] = self::http($person, 'DELETE', self::DAV . $person . '/' . rawurlencode($name), []);
		self::assertSame(204, $status, "DELETE $name answered $status");
	}

	/**
	 * Asks for the Review until its state is one of $states, or fails after $seconds.
	 *
	 * @param list<string> $states
	 * @return array<string, mixed> the Review
	 */
	protected static function waitFor(string $person, int $id, array $states, int $seconds): array {
		$until = time() + $seconds;
		do {
			$answer = self::ocs($person, 'GET', "/apps/ia/api/v1/reviews/$id");
			self::assertSame(200, $answer['status'], "GET reviews/$id answered {$answer['status']}");
			if (in_array($answer['data']['review']['state'], $states, true)) {
				return $answer['data']['review'];
			}
			sleep(5);
		} while (time() < $until);
		self::fail("Review $id was still {$answer['data']['review']['state']} after {$seconds} s");
	}

	/** The Nextcloud log files that hold $needle. @return list<string> */
	protected static function logsHolding(string $needle): array {
		return array_values(array_filter(glob(self::LOGS) ?: [], static fn (string $log): bool => str_contains((string)file_get_contents($log), $needle)));
	}

	/**
	 * @param list<string> $headers
	 * @return array{0: int, 1: string} status and body
	 */
	private static function http(?string $person, string $method, string $url, array $headers, ?string $body = null): array {
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => $headers,
		]);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}
		if ($person !== null) {
			curl_setopt($curl, CURLOPT_USERPWD, $person . ':' . self::$people[$person]);
		}
		$text = curl_exec($curl);
		if ($text === false) {
			throw new RuntimeException("$method $url: " . curl_error($curl));
		}
		return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$text];
	}
}
