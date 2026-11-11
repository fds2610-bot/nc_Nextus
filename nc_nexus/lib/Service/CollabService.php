<?php

declare(strict_types=1);

namespace OCA\Nexus\Service;

use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Talk\IBroker;
use Psr\Log\LoggerInterface;

/**
 * Anbindung von Sitzungen an Kalender (CalDAV) und Talk (Kapitel 07 §6). Beides ist optional:
 * Fehlt Talk oder hat das Konto keinen beschreibbaren Kalender, bleibt die Sitzung ohne.
 */
class CollabService {
	public function __construct(
		private ICalendarManager $calendars,
		private IBroker $talk,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{talk: bool, calendar: bool} */
	public function capabilities(string $userId): array {
		return ['talk' => $this->talk->hasBackend(), 'calendar' => $this->calendarFor($userId) !== null];
	}

	/**
	 * Talk-Raum für die Sitzung, Mitglieder als Moderatoren.
	 *
	 * @param list<string> $userIds
	 * @return array{token: string, url: string}|null
	 */
	public function createRoom(string $name, array $userIds): ?array {
		if (!$this->talk->hasBackend()) {
			return null;
		}
		try {
			$users = array_values(array_filter(array_map(fn (string $u) => $this->userManager->get($u), array_unique($userIds)), static fn ($u) => $u instanceof IUser));
			$conversation = $this->talk->createConversation($name, $users);
			return ['token' => $conversation->getId(), 'url' => $conversation->getAbsoluteUrl()];
		} catch (\Throwable $e) {
			$this->logger->warning('Nexus: Talk room for session could not be created', ['exception' => $e]);
			return null;
		}
	}

	public function deleteRoom(string $token): void {
		try {
			if ($this->talk->hasBackend()) {
				$this->talk->deleteConversation($token);
			}
		} catch (\Throwable $e) {
			$this->logger->info('Nexus: Talk room could not be deleted', ['exception' => $e]);
		}
	}

	/**
	 * Termin im Kalender des Anlegenden, Mitglieder als Teilnehmende (sofern E-Mail bekannt).
	 *
	 * @param list<string> $attendees
	 * @return string|null UID des Termins
	 */
	public function createEvent(string $organizer, string $title, int $start, int $durationMin, ?string $location, string $description, array $attendees): ?string {
		$calendar = $this->calendarFor($organizer);
		if ($calendar === null) {
			return null;
		}
		$uid = 'nexus-' . bin2hex(random_bytes(8));
		$lines = [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nextcloud//Nexus//DE', 'CALSCALE:GREGORIAN', 'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'DTSTART:' . gmdate('Ymd\THis\Z', $start),
			'DTEND:' . gmdate('Ymd\THis\Z', $start + $durationMin * 60),
			'SUMMARY:' . self::escape($title),
			'DESCRIPTION:' . self::escape($description),
		];
		if ($location !== null && $location !== '') {
			$lines[] = 'LOCATION:' . self::escape($location);
		}
		$organizerMail = $this->userManager->get($organizer)?->getEMailAddress();
		if ($organizerMail !== null && $organizerMail !== '') {
			$lines[] = 'ORGANIZER:mailto:' . $organizerMail;
			foreach (array_unique($attendees) as $a) {
				$mail = $a === $organizer ? null : $this->userManager->get($a)?->getEMailAddress();
				if ($mail !== null && $mail !== '') {
					$lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $mail;
				}
			}
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		$ics = implode("\r\n", $lines) . "\r\n";
		try {
			try {
				$calendar->createFromString($uid . '.ics', $ics);
			} catch (\Throwable $e) {
				// Einladungen per Mail können scheitern (kein Mailserver) – dann ohne Einladungen
				$this->logger->info('Nexus: calendar invitation failed, storing event without scheduling', ['exception' => $e]);
				$calendar->createFromStringMinimal($uid . '.ics', $ics);
			}
			return $uid;
		} catch (\Throwable $e) {
			$this->logger->warning('Nexus: calendar event for session could not be created', ['exception' => $e]);
			return null;
		}
	}

	private function calendarFor(string $userId): ?ICreateFromString {
		try {
			foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $userId) as $calendar) {
				if ($calendar instanceof ICreateFromString
					&& (!$calendar instanceof ICalendarIsWritable || $calendar->isWritable())
					&& !$calendar->isDeleted()) {
					return $calendar;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->info('Nexus: calendars could not be read', ['exception' => $e]);
		}
		return null;
	}

	private static function escape(string $text): string {
		return str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $text);
	}
}
