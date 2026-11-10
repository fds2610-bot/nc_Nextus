<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCA\Nexus\Db\Demand;
use OCA\Nexus\Db\DemandMapper;

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
	) {
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
