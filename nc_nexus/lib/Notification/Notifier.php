<?php

declare(strict_types=1);

namespace OCA\Nexus\Notification;

use OCA\Nexus\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Texte der Benachrichtigungen (Kapitel 06 §4.4) in der Sprache des Empfängers.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Nexus');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$p = $notification->getSubjectParameters();
		$text = match ($notification->getSubject()) {
			'gate_decided' => $l->t('Decision on %1$s: %2$s', [(string)($p['title'] ?? ''), trim(($p['gate'] ?? '') . ' ' . match ((string)($p['outcome'] ?? '')) {
				'approved' => $l->t('Approved'),
				'approved_with_conditions' => $l->t('Approved with conditions'),
				'rework' => $l->t('Rework'),
				'deferred' => $l->t('Deferred'),
				'rejected' => $l->t('Rejected'),
				'cancelled' => $l->t('Cancelled'),
				default => (string)($p['outcome'] ?? ''),
			})]),
			'condition_due' => $l->t('Condition due on %1$s: %2$s', [(string)($p['due'] ?? ''), (string)($p['title'] ?? '')]),
			'condition_overdue' => $l->t('Condition overdue since %1$s: %2$s', [(string)($p['due'] ?? ''), (string)($p['title'] ?? '')]),
			'report_overdue' => $l->t('Status report for %1$s is overdue', [(string)($p['title'] ?? '')]),
			'conflict_detected' => $l->t('Resource conflict in %1$s (%2$s)', [(string)($p['pool'] ?? ''), (string)($p['period'] ?? '')]),
			'conflict_escalated' => $l->t('Resource conflict escalated to level %1$s: %2$s', [(string)($p['level'] ?? ''), (string)($p['pool'] ?? '')]),
			'change_submitted' => $l->t('Change request submitted for %1$s: %2$s', [(string)($p['project'] ?? ''), (string)($p['title'] ?? '')]),
			'digest' => $l->t('Daily overview: %1$s to triage, %2$s overdue conditions, %3$s open conflicts, %4$s overdue status reports', [
				(string)($p['triage'] ?? 0), (string)($p['conditions'] ?? 0), (string)($p['conflicts'] ?? 0), (string)($p['reports'] ?? 0),
			]),
			default => throw new UnknownNotificationException(),
		};
		$notification->setParsedSubject($text);
		$notification->setLink($this->url->linkToRouteAbsolute('nc_nexus.page.index'));
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
