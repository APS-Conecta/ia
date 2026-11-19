<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The seam (gestion-aio#162, Testing Decisions): a test sees «IA» as a person or an operator
 * does, through its OCS API, its pages and occ, inside the throwaway instance's Nextcloud
 * container. Nothing here loads an app class. Every person a test needs is a fresh account,
 * removed when its class ends.
 */
abstract class SeamTestCase extends TestCase {
	private const BASE = 'http://localhost';
	private const OCC = '/var/www/html/occ';

	/** @var array<string, string> each account this class made, with its password */
	private static array $people = [];

	public static function tearDownAfterClass(): void {
		foreach (array_keys(self::$people) as $name) {
			self::occ('user:delete', $name);
		}
		self::$people = [];
	}

	/** A fresh account, unique to this run, and its login name. */
	protected static function person(string $role): string {
		$name = $role . '-' . bin2hex(random_bytes(4));
		$password = bin2hex(random_bytes(16));
		self::occ(['OC_PASS' => $password], 'user:add', '--password-from-env', $name);
		self::$people[$name] = $password;
		return $name;
	}

	/**
	 * occ as the web server's user; a failing command fails the test with its output.
	 *
	 * @param array<string, string>|string $env extra environment, or the first argument
	 */
	protected static function occ(array|string $env, string ...$args): string {
		if (is_string($env)) {
			array_unshift($args, $env);
			$env = [];
		}
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
	 * An OCS call as the person (null: no credentials), answered as {status, data}.
	 *
	 * @return array{status: int, data: mixed}
	 */
	protected static function ocs(?string $person, string $method, string $path, ?array $body = null): array {
		[$status, $text] = self::request($person, $method, '/ocs/v2.php' . $path, $body, ['OCS-APIRequest: true', 'Accept: application/json']);
		$json = json_decode($text, true);
		return ['status' => $status, 'data' => is_array($json) ? ($json['ocs']['data'] ?? null) : null];
	}

	/**
	 * A page or a file, by its path on the server, as the person, answered as {status, body}.
	 *
	 * @return array{status: int, body: string}
	 */
	protected static function page(?string $person, string $path): array {
		[$status, $text] = self::request($person, 'GET', $path, null, []);
		return ['status' => $status, 'body' => $text];
	}

	/** @return array{int, string} */
	private static function request(?string $person, string $method, string $path, ?array $body, array $headers): array {
		$curl = curl_init(self::BASE . $path);
		$options = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => $headers,
		];
		if ($person !== null) {
			$options[CURLOPT_USERPWD] = $person . ':' . self::$people[$person];
		}
		if ($body !== null) {
			$options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
			$options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
		}
		curl_setopt_array($curl, $options);
		$text = curl_exec($curl);
		if ($text === false) {
			throw new RuntimeException("$method $path: " . curl_error($curl));
		}
		return [(int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string)$text];
	}
}
