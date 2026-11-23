<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Ia\Tests\Seam;

/**
 * «IA» never reads the «Recursos Humanos» folder (APS-Conecta/ia#32, recursos-humanos ADR 0004):
 * the team folder Unidades/Recursos Humanos and anything in it is refused, with the reason, as a
 * Bulletin's Source, an Automatic action's folder or a Review's document, even for its members and
 * through a share; and a folder above it never brings its documents in.
 */
final class RecursosHumanosTest extends SeamTestCase {
	private const API = '/apps/ia/api/v1';

	private static string $ana;
	private static string $bea;
	private static string $anaEditors;
	private static string $beaEditors;
	private static string $readers;
	private static int $teamFolder;
	/** @var array<string, int> each file and folder of the «Recursos Humanos» folder, by its path in Ana's Files */
	private static array $inside = [];

	public static function setUpBeforeClass(): void {
		// Ana is in the HR cargo group the team folder is for; Bea is not, and gets a share of one of its folders.
		self::$ana = self::person();
		self::$bea = self::person();
		$hr = self::group(self::$ana);
		self::$anaEditors = self::group(self::$ana);
		self::$beaEditors = self::group(self::$bea);
		self::$readers = self::group(self::person());
		self::editorGroups(self::$anaEditors, self::$beaEditors);
		self::$teamFolder = (int)self::occ('groupfolders:create', 'Unidades/Recursos Humanos');
		self::occ('groupfolders:group', (string)self::$teamFolder, $hr, 'read', 'write', 'delete', 'share');

		self::$inside['Unidades/Recursos Humanos'] = self::file(self::$ana, 'Unidades/Recursos Humanos')['fileId'];
		self::$inside['Unidades/Recursos Humanos/ficha.txt'] = self::upload(self::$ana, 'Unidades/Recursos Humanos/ficha.txt', "Ficha del personal\nFeriado legal: 15 días hábiles.\n");
		self::$inside['Unidades/Recursos Humanos/Licencias'] = self::folder(self::$ana, 'Unidades/Recursos Humanos/Licencias');
		self::$inside['Unidades/Recursos Humanos/Licencias/licencia.txt'] = self::upload(self::$ana, 'Unidades/Recursos Humanos/Licencias/licencia.txt', "Licencia médica\nReposo de 7 días.\n");
		self::upload(self::$ana, 'Unidades/norma.txt', "Norma de higiene de manos\nDirigida a: todo el personal clínico.\n");
		self::shareWithGroup(self::$ana, '/Unidades/Recursos Humanos/Licencias', self::$beaEditors);
		self::upload(self::$bea, 'propio.txt', "Protocolo de curaciones\nDirigido a: Enfermera.\n");
	}

	public static function tearDownAfterClass(): void {
		self::occ('groupfolders:delete', '--force', (string)self::$teamFolder);
		parent::tearDownAfterClass();
	}

	public function testItsMembersCannotChooseItOrAnythingInItAndAreToldWhy(): void {
		foreach (self::$inside as $path => $id) {
			$type = str_ends_with($path, '.txt') ? 'file' : 'folder';
			self::assertRefused(self::ocs(self::$ana, 'POST', self::API . '/bulletins', self::bulletinFor(self::$anaEditors, $type, $id)), "$path as a Source");
			self::assertRefused(self::ocs(self::$ana, 'POST', self::API . '/bulletins/preview', ['sources' => [['type' => $type, 'id' => (string)$id]], 'readers' => [self::$readers]]), "$path in the preview");
			if ($type === 'folder') {
				self::assertRefused(self::ocs(self::$ana, 'POST', self::API . '/actions', ['name' => 'Revisar', 'editorGroup' => self::$anaEditors, 'folder' => (string)$id, 'instructions' => '']), "$path as an action's folder");
			} else {
				self::assertRefused(self::ocs(self::$ana, 'POST', self::API . '/reviews', ['fileId' => $id]), "$path reviewed");
			}
		}
		self::assertNothingLeft(self::$ana);
	}

	public function testNeitherCanSomeoneItIsSharedWith(): void {
		$folder = self::$inside['Unidades/Recursos Humanos/Licencias'];
		$file = self::$inside['Unidades/Recursos Humanos/Licencias/licencia.txt'];
		self::assertSame(207, self::dav(self::$bea, 'PROPFIND', '/remote.php/dav/files/' . self::$bea . '/Licencias/licencia.txt', ''), 'Bea opens the share');
		self::assertRefused(self::ocs(self::$bea, 'POST', self::API . '/reviews', ['fileId' => $file]), 'the shared file reviewed');
		self::assertRefused(self::ocs(self::$bea, 'POST', self::API . '/bulletins', self::bulletinFor(self::$beaEditors, 'folder', $folder)), 'the shared folder as a Source');
		self::assertRefused(self::ocs(self::$bea, 'POST', self::API . '/actions', ['name' => 'Revisar', 'editorGroup' => self::$beaEditors, 'folder' => (string)$folder, 'instructions' => '']), "the shared folder as an action's folder");
		self::assertNothingLeft(self::$bea);
	}

	/** @depends testItsMembersCannotChooseItOrAnythingInItAndAreToldWhy */
	public function testAFolderAboveItNeverBringsItsDocuments(): void {
		// Ana's Unidades holds the team folder's mount; Bea's Files hold the share of Licencias.
		self::bulletin(self::$ana, 'Boletín de unidades', self::$anaEditors, self::$readers, ['folder' => self::file(self::$ana, 'Unidades')['fileId']]);
		self::bulletin(self::$bea, 'Boletín de Bea', self::$beaEditors, self::$readers, ['folder' => self::file(self::$bea, '')['fileId']]);
		self::assertSame(['norma.txt'], self::documents(self::$ana));
		$bea = self::documents(self::$bea);
		self::assertContains('propio.txt', $bea);
		self::assertNotContains('licencia.txt', $bea, 'the share of Licencias is not read');
	}

	public function testABulletinWhoseSourceMovesIntoItStillOpensAndSaysWhy(): void {
		$file = self::upload(self::$ana, 'movida.txt', "Turnos\nEnero.\n");
		$id = self::bulletin(self::$ana, 'Boletín movido', self::$anaEditors, self::$readers, ['movida' => $file]);
		self::transfer(self::$ana, 'MOVE', 'movida.txt', 'Unidades/Recursos Humanos/movida.txt');
		$answer = self::ocs(self::$ana, 'GET', self::API . "/bulletins/$id");
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		self::assertStringContainsString('Recursos Humanos', $answer['data']['bulletin']['sources'][0]['name']);
	}

	/** @param array{status: int, data: array<string, mixed>} $answer */
	private static function assertRefused(array $answer, string $what): void {
		self::assertSame(422, $answer['status'], "$what: " . json_encode($answer['data']));
		self::assertStringContainsString('Recursos Humanos', (string)$answer['data']['message'], $what);
	}

	/** No Bulletin, Automatic action or Review came of a refusal. */
	private static function assertNothingLeft(string $person): void {
		self::assertSame([], self::ocs($person, 'GET', self::API . '/bulletins')['data']['bulletins']);
		self::assertSame([], self::ocs($person, 'GET', self::API . '/actions')['data']['actions']);
		self::assertSame([], self::ocs($person, 'GET', self::API . '/reviews')['data']['reviews']);
	}

	/** @return array<string, mixed> a daily Bulletin for $editors with the one Source $id */
	private static function bulletinFor(string $editors, string $type, int $id): array {
		return ['name' => 'Boletín de personal', 'editorGroup' => $editors, 'sources' => [['type' => $type, 'id' => (string)$id]],
			'readers' => [self::$readers], 'schedule' => ['kind' => 'daily']];
	}

	/** @return list<string> the names of the documents $person's «Documentos» lists */
	private static function documents(string $person): array {
		$answer = self::ocs($person, 'GET', self::API . '/documents');
		self::assertSame(200, $answer['status'], json_encode($answer['data']));
		return array_column($answer['data']['documents'], 'name');
	}
}
