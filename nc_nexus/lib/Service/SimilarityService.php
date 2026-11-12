<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;
use OCA\Nexus\Db\OrgUnitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

/**
 * Dublettenvorschlag beim Antragseingang (DEM-001, Kapitel 02 §2): ähnliche offene und
 * zurückgestellte Anträge über Titel und Problembeschreibung finden.
 *
 * Bewusst einfach und datenbankunabhängig (SQLite/PostgreSQL/MariaDB): Wortstämme vergleichen
 * statt Volltextindex. Anträge, die das Konto nicht lesen darf, erscheinen nicht – auch nicht als Anzahl.
 */
class SimilarityService {
	/** Status, in denen ein Antrag als mögliche Dublette gilt */
	public const OPEN_STATUSES = ['submitted', 'query', 'further_analysis', 'deferred', 'in_qualification'];

	private const MIN_SCORE = 0.3;
	private const LIMIT = 5;
	private const STEM_LENGTH = 6;

	private const STOPWORDS = [
		'aber', 'alle', 'auch', 'bitte', 'damit', 'dass', 'dein', 'denn', 'diese', 'dieser', 'durch', 'eine', 'einem',
		'einen', 'einer', 'eines', 'fuer', 'haben', 'hier', 'ihre', 'jetzt', 'kein', 'keine', 'muss', 'nach', 'neue',
		'neuen', 'neuer', 'nicht', 'noch', 'oder', 'ohne', 'sein', 'sind', 'soll', 'sollen', 'ueber', 'unser', 'unsere',
		'viel', 'wenn', 'werden', 'wird', 'wurde', 'with', 'that', 'this', 'from', 'have', 'into', 'should', 'will',
		'about', 'their', 'there', 'which', 'would', 'projekt', 'project', 'antrag', 'demand',
	];

	public function __construct(
		private DemandMapper $mapper,
		private PermissionService $permissions,
		private OrgUnitMapper $orgUnits,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Vorschläge für die Anzeige: mit Bereich (Org-Einheit) und antragstellender Person,
	 * damit sich eine Dublette ohne Öffnen des Antrags einordnen lässt.
	 *
	 * @return list<array{id: int, key: string, title: string, status: string, score: float, orgUnitId: ?int, orgUnitName: ?string, requestorId: string, requestorName: string}>
	 */
	public function suggestions(string $userId, string $title, string $problem = '', ?int $excludeId = null): array {
		$units = [];
		$names = [];
		$out = [];
		foreach ($this->similar($userId, $title, $problem, $excludeId) as $m) {
			$d = $m['demand'];
			$unitId = $d->getOrgUnitId();
			if ($unitId !== null && !array_key_exists($unitId, $units)) {
				try {
					$units[$unitId] = $this->orgUnits->find($unitId)->getName();
				} catch (DoesNotExistException) {
					$units[$unitId] = null;
				}
			}
			$uid = $d->getRequestorId();
			if (!array_key_exists($uid, $names)) {
				$names[$uid] = $this->userManager->getDisplayName($uid) ?? $uid;
			}
			$out[] = [
				'id' => (int)$d->getId(),
				'key' => (string)$d->getDemandKey(),
				'title' => $d->getTitle(),
				'status' => $d->getStatus(),
				'score' => $m['score'],
				'orgUnitId' => $unitId,
				'orgUnitName' => $unitId !== null ? $units[$unitId] : null,
				'requestorId' => $uid,
				'requestorName' => $names[$uid],
			];
		}
		return $out;
	}

	/**
	 * @return list<array{demand: Demand, score: float}>
	 */
	public function similar(string $userId, string $title, string $problem = '', ?int $excludeId = null): array {
		$query = $this->stems($title . ' ' . $title . ' ' . $problem);
		if ($query === []) {
			return [];
		}
		$matches = [];
		foreach ($this->mapper->findAll() as $demand) {
			if ($demand->getId() === $excludeId || !in_array($demand->getStatus(), self::OPEN_STATUSES, true)) {
				continue;
			}
			$score = $this->score($query, $this->stems($demand->getTitle() . ' ' . $demand->getTitle() . ' ' . $demand->getProblemStatement()));
			if ($score >= self::MIN_SCORE && $this->permissions->canReadDemand($userId, $demand)) {
				$matches[] = ['demand' => $demand, 'score' => $score];
			}
		}
		usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);
		return array_slice($matches, 0, self::LIMIT);
	}

	/**
	 * Gewichteter Überlapp: Anteil der Stämme der Anfrage, die im Kandidaten vorkommen
	 * (Titelwörter zählen doppelt, weil der Titel zweimal eingeht).
	 *
	 * @param array<string, int> $query
	 * @param array<string, int> $candidate
	 */
	public function score(array $query, array $candidate): float {
		if ($query === [] || $candidate === []) {
			return 0.0;
		}
		$common = 0;
		foreach ($query as $stem => $weight) {
			if (isset($candidate[$stem])) {
				$common += min($weight, $candidate[$stem]);
			}
		}
		$total = min(array_sum($query), array_sum($candidate));
		return $total > 0 ? round($common / $total, 3) : 0.0;
	}

	/**
	 * Wortstämme mit Häufigkeit: klein, Umlaute aufgelöst, Stoppwörter und kurze Wörter entfernt,
	 * auf die ersten Zeichen gekürzt (fängt Plural- und Beugungsformen grob ab).
	 *
	 * @return array<string, int>
	 */
	public function stems(?string $text): array {
		$text = mb_strtolower((string)$text);
		$text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
		$stems = [];
		foreach (preg_split('/[^a-z0-9]+/', $text) ?: [] as $word) {
			if (strlen($word) < 4 || in_array($word, self::STOPWORDS, true)) {
				continue;
			}
			$stem = substr($word, 0, self::STEM_LENGTH);
			$stems[$stem] = ($stems[$stem] ?? 0) + 1;
		}
		return $stems;
	}
}
