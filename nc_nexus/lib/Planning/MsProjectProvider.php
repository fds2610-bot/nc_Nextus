<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\Exception\ValidationException;

/**
 * MS Project als Datei (MSPDI, „XML-Format“ von Project und vielen anderen Planungstools,
 * Kapitel 07 §4.2): Der Plan wird als Schnappschuss importiert, Herkunft „Datei vom …“.
 * Ein neuer Stand wird durch erneuten Import übernommen. Der Export liefert einen Startplan
 * mit Projektrahmen und Governance-Meilensteinen für MS Project (Kapitel 07 §3: Rahmentermine und
 * Meilensteine führt die Governance).
 */
class MsProjectProvider implements IPlanningProvider, IPlanningFileImport {
	public const ID = 'mspdi';
	public const MAX_BYTES = 10 * 1024 * 1024;

	public function __construct(
		private PlanningConfig $config,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getName(): string {
		return 'MS Project (XML)';
	}

	public function getCapabilities(): array {
		return [self::CAP_IMPORT];
	}

	/** Import am Vorhaben; der Export hängt nicht an der Verknüpfung (canExport) */
	public function isAvailable(string $userId): bool {
		return $this->config->mspdiImport();
	}

	public function canExport(): bool {
		return $this->config->mspdiExport();
	}

	public function listProjects(string $userId): array {
		return [];
	}

	public function createProject(string $userId, string $title, ?string $description): array {
		throw new ValidationException('This planning tool only supports file import');
	}

	public function tasks(string $userId, string $externalId): array {
		throw new ValidationException('This planning tool only supports file import');
	}

	public function parseFile(string $fileName, string $content): array {
		return self::parse($content, $fileName);
	}

	/**
	 * MSPDI lesen: Sammelvorgänge, leere Zeilen und den Projekt-Vorgang (UID 0) überspringen.
	 *
	 * @return array{title: string, tasks: list<array{title: string, start_date: ?string, end_date: ?string, status: string, progress: float, milestone: bool}>}
	 * @throws ValidationException
	 */
	public static function parse(string $content, string $fileName = ''): array {
		if ($content === '' || strlen($content) > self::MAX_BYTES) {
			throw new ValidationException('The file is empty or larger than 10 MB');
		}
		// keine DTD/Entitäten (XXE, Entity-Expansion)
		if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
			throw new ValidationException('The file is not a MS Project XML file (MSPDI)');
		}
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$root = $doc->documentElement;
		if (!$ok || $root === null || $root->localName !== 'Project') {
			throw new ValidationException('The file is not a MS Project XML file (MSPDI)');
		}
		$title = self::text($root, 'Title') ?: self::text($root, 'Name') ?: '';
		$title = (string)preg_replace('/\.(xml|mpp)$/i', '', $title);
		if ($title === '') {
			$title = (string)preg_replace('/\.xml$/i', '', basename($fileName)) ?: 'MS Project';
		}
		$tasks = [];
		foreach (self::children($root, 'Tasks') as $list) {
			foreach (self::children($list, 'Task') as $task) {
				if (self::text($task, 'UID') === '0' || self::text($task, 'Summary') === '1' || self::text($task, 'IsNull') === '1' || self::text($task, 'Active') === '0') {
					continue;
				}
				$progress = max(0.0, min(100.0, (float)(self::text($task, 'PercentComplete') ?: 0)));
				$start = self::day(self::text($task, 'Start'));
				$end = self::day(self::text($task, 'Finish'));
				$tasks[] = [
					'title' => mb_substr(self::text($task, 'Name') ?? '', 0, 255),
					'start_date' => $start ?? $end,
					'end_date' => $end ?? $start,
					'status' => $progress >= 100 ? 'done' : ($progress > 0 ? 'in_progress' : 'not_started'),
					'progress' => $progress,
					'milestone' => self::text($task, 'Milestone') === '1',
				];
			}
		}
		return ['title' => mb_substr(trim($title), 0, 200), 'tasks' => $tasks];
	}

	/**
	 * Startplan als MSPDI schreiben: Projekt-Vorgang (UID 0), Projektrahmen als Vorgang mit
	 * „Anfang nicht früher als“, Meilensteine mit „Ende nicht später als“ ihrem Termin.
	 *
	 * @param list<array{name: string, date: string, external?: bool}> $milestones
	 */
	public static function write(string $title, string $start, string $end, string $frameName, array $milestones): string {
		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$ns = 'http://schemas.microsoft.com/project';
		$project = $doc->createElementNS($ns, 'Project');
		$doc->appendChild($project);
		$add = static function (\DOMElement $parent, string $name, string $value) use ($doc, $ns): void {
			$el = $doc->createElementNS($ns, $name);
			$el->appendChild($doc->createTextNode($value));
			$parent->appendChild($el);
		};
		$at = static fn (string $day, string $time) => $day . 'T' . $time;
		$add($project, 'SaveVersion', '14');
		$add($project, 'Title', $title);
		$add($project, 'ScheduleFromStart', '1');
		$add($project, 'StartDate', $at($start, '08:00:00'));
		$add($project, 'FinishDate', $at($end, '17:00:00'));
		$add($project, 'MinutesPerDay', '480');
		$add($project, 'MinutesPerWeek', '2400');
		$add($project, 'DaysPerMonth', '20');
		$tasks = $doc->createElementNS($ns, 'Tasks');
		$project->appendChild($tasks);
		$uid = 0;
		$task = static function (string $name, string $from, string $to, int $level, array $extra) use ($doc, $ns, $tasks, $add, &$uid): void {
			$t = $doc->createElementNS($ns, 'Task');
			$tasks->appendChild($t);
			$add($t, 'UID', (string)$uid);
			$add($t, 'ID', (string)$uid);
			$add($t, 'Name', $name);
			$add($t, 'OutlineLevel', (string)$level);
			$add($t, 'Start', $from);
			$add($t, 'Finish', $to);
			foreach ($extra as $k => $v) {
				$add($t, $k, (string)$v);
			}
			$uid++;
		};
		$hours = self::workdays($start, $end) * 8;
		$task($title, $at($start, '08:00:00'), $at($end, '17:00:00'), 0, ['Summary' => 1, 'Duration' => 'PT' . $hours . 'H0M0S']);
		$task($frameName, $at($start, '08:00:00'), $at($end, '17:00:00'), 1, ['Duration' => 'PT' . $hours . 'H0M0S', 'DurationFormat' => 7, 'Milestone' => 0, 'ConstraintType' => 4, 'ConstraintDate' => $at($start, '08:00:00')]);
		foreach ($milestones as $m) {
			$task($m['name'], $at($m['date'], '17:00:00'), $at($m['date'], '17:00:00'), 1, ['Duration' => 'PT0H0M0S', 'DurationFormat' => 7, 'Milestone' => 1, 'ConstraintType' => 7, 'ConstraintDate' => $at($m['date'], '17:00:00')]);
		}
		return (string)$doc->saveXML();
	}

	/** Arbeitstage Mo–Fr einschließlich, mindestens 1 */
	private static function workdays(string $start, string $end): int {
		$from = new \DateTimeImmutable($start);
		$to = new \DateTimeImmutable($end);
		$days = 0;
		for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
			if ((int)$d->format('N') <= 5) {
				$days++;
			}
		}
		return max(1, $days);
	}

	/** @return list<\DOMElement> */
	private static function children(\DOMElement $parent, string $name): array {
		$result = [];
		foreach ($parent->childNodes as $node) {
			if ($node instanceof \DOMElement && $node->localName === $name) {
				$result[] = $node;
			}
		}
		return $result;
	}

	private static function text(\DOMElement $parent, string $name): ?string {
		$nodes = self::children($parent, $name);
		return $nodes === [] ? null : trim($nodes[0]->textContent);
	}

	private static function day(?string $value): ?string {
		return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
	}
}
