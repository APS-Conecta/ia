<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * The «IA» admin settings (APS-Conecta/ia#3): the Editor groups and Off hours, set by an admin or
 * a group the admin delegates the page to, and the caller's own Editor groups.
 */
final class AdminSettingsTest extends SeamTestCase {
	private const SETTINGS = '/apps/ia/api/v1/admin/settings';
	private const DELEGATED = 'OCA\Ia\Settings\Admin';

	public function testOffHoursAreTenAtNightToSixInTheMorningUntilAnAdminChangesThem(): void {
		self::occ('config:app:delete', 'ia', 'off_hours');
		$answer = self::ocs(self::admin(), 'GET', self::SETTINGS);
		self::assertSame(200, $answer['status']);
		self::assertSame(['start' => '22:00', 'end' => '06:00'], $answer['data']['offHours']);
	}

	public function testAnAdminSetsTheEditorGroupsAndOffHours(): void {
		$admin = self::admin();
		$group = self::group();
		$answer = self::ocs($admin, 'PUT', self::SETTINGS, ['editorGroups' => [$group], 'offHours' => ['start' => '21:30', 'end' => '05:00']]);
		self::assertSame(200, $answer['status']);
		$read = self::ocs($admin, 'GET', self::SETTINGS)['data'];
		self::assertSame([$group], array_column($read['editorGroups'], 'id'));
		self::assertSame(['start' => '21:30', 'end' => '05:00'], $read['offHours']);
	}

	public function testADelegatedGroupChangesThePageAndAnyoneElseIsForbidden(): void {
		$head = self::person();
		$delegated = self::group($head);
		self::occ('admin-delegation:add', self::DELEGATED, $delegated);
		try {
			self::assertSame(200, self::ocs($head, 'GET', self::SETTINGS)['status']);
			self::assertSame(200, self::ocs($head, 'PUT', self::SETTINGS, ['editorGroups' => [], 'offHours' => ['start' => '22:00', 'end' => '06:00']])['status']);
			$someone = self::person();
			self::assertSame(403, self::ocs($someone, 'GET', self::SETTINGS)['status']);
			self::assertSame(403, self::ocs($someone, 'PUT', self::SETTINGS, ['editorGroups' => [], 'offHours' => ['start' => '22:00', 'end' => '06:00']])['status']);
		} finally {
			self::occ('admin-delegation:remove', self::DELEGATED, $delegated);
		}
	}

	public function testTheCallerKnowsTheirEditorGroupsAndARemovedGroupStopsCountingAtOnce(): void {
		$admin = self::admin();
		$editor = self::person();
		$editors = self::group($editor);
		$other = self::group($editor);
		self::ocs($admin, 'PUT', self::SETTINGS, ['editorGroups' => [$editors], 'offHours' => ['start' => '22:00', 'end' => '06:00']]);

		$me = self::ocs($editor, 'GET', '/apps/ia/api/v1/me');
		self::assertSame(200, $me['status']);
		self::assertSame([$editors], array_column($me['data']['editorGroups'], 'id'), 'only the groups that are Editor groups count');
		self::assertSame([], self::ocs(self::person(), 'GET', '/apps/ia/api/v1/me')['data']['editorGroups']);

		self::ocs($admin, 'PUT', self::SETTINGS, ['editorGroups' => [$other], 'offHours' => ['start' => '22:00', 'end' => '06:00']]);
		self::assertSame([$other], array_column(self::ocs($editor, 'GET', '/apps/ia/api/v1/me')['data']['editorGroups'], 'id'));
	}

	public function testAnUnknownGroupOrAMalformedHourIsRefused(): void {
		$admin = self::admin();
		foreach ([
			['editorGroups' => ['no-such-group-' . bin2hex(random_bytes(4))], 'offHours' => ['start' => '22:00', 'end' => '06:00']],
			['editorGroups' => [], 'offHours' => ['start' => '25:00', 'end' => '06:00']],
			['editorGroups' => [], 'offHours' => ['start' => '22:00', 'end' => '22:00']],
		] as $settings) {
			$answer = self::ocs($admin, 'PUT', self::SETTINGS, $settings);
			self::assertSame(422, $answer['status'], json_encode($settings));
			self::assertNotSame('', trim($answer['data']['message']));
		}
	}

	public function testTestsSetOffHoursToCoverTheRun(): void {
		$hours = self::offHoursNow();
		self::assertSame($hours, self::ocs(self::admin(), 'GET', self::SETTINGS)['data']['offHours']);
	}
}
