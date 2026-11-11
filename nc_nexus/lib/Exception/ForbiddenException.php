<?php

declare(strict_types=1);

namespace OCA\Nexus\Exception;

/**
 * Keine Berechtigung. Wie bei der ValidationException ist die Meldung ein Übersetzungsschlüssel,
 * Werte kommen als Platzhalter (%1$s …) über $params.
 */
class ForbiddenException extends \Exception {
	/** @var list<string> */
	public readonly array $params;

	/**
	 * @param list<string> $params
	 */
	public function __construct(string $message = '', array $params = []) {
		$this->params = $params;
		$this->rawKey = $message;
		parent::__construct($params === [] ? $message : vsprintf($message, $params));
	}

	/** Übersetzungsschlüssel ohne eingesetzte Werte */
	public function key(): string {
		return $this->params === [] ? $this->getMessage() : $this->rawKey;
	}

	private string $rawKey = '';
}
