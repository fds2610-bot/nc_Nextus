<?php

declare(strict_types=1);

namespace OCA\Nexus\Exception;

/**
 * Fachlich ungültige Eingabe. Die Meldung ist ein Übersetzungsschlüssel; Werte darin stehen als
 * Platzhalter (%1$s …) und kommen über $params, damit die Meldung übersetzbar bleibt.
 */
class ValidationException extends \Exception {
	/**
	 * @param list<string> $params
	 */
	public function __construct(public readonly string $key, public readonly array $params = []) {
		parent::__construct($params === [] ? $key : vsprintf($key, $params));
	}
}
