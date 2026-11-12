<?php

declare(strict_types=1);

namespace OCA\Nexus\Planning;

use OCA\Nexus\AppInfo\Application;
use OCA\Nexus\Exception\ValidationException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use Psr\Log\LoggerInterface;

/**
 * JSON-Aufrufe an REST-/OCS-Schnittstellen anderer Systeme mit einheitlicher Fehlerbehandlung:
 * Fehler werden als ValidationException mit festem Code gemeldet (unreachable, unauthorized …).
 * Hinweis: Ziele im lokalen Netz lässt der HTTP-Client von Nextcloud nur mit
 * `allow_local_remote_servers` zu.
 */
class OcsClient {
	public function __construct(
		private IClientService $clients,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array{0: string, 1: string} $auth
	 * @param array<string, string> $headers
	 * @param array<string, string|int> $query
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws ValidationException
	 */
	public function request(string $method, string $url, array $auth, array $headers = [], array $query = [], ?array $body = null): array {
		$options = ['auth' => $auth, 'headers' => $headers + ['Accept' => 'application/json'], 'timeout' => 15, 'http_errors' => false];
		if ($query !== []) {
			$options['query'] = $query;
		}
		if ($body !== null) {
			$options['json'] = $body;
		}
		try {
			$client = $this->clients->newClient();
			$response = $method === 'POST' ? $client->post($url, $options) : $client->get($url, $options);
			$status = $response->getStatusCode();
			$data = json_decode((string)$response->getBody(), true);
		} catch (\Throwable $e) {
			$this->logger->info('nc_nexus: planning tool request failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			if (in_array(strtolower((string)parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
				throw new ValidationException('“localhost” is this server itself. Enter the address under which the other Nextcloud can be reached from here.');
			}
			if ($e instanceof LocalServerException) {
				// Nextcloud lässt Ziele im lokalen Netz nur mit allow_local_remote_servers zu
				throw new ValidationException('The address is in the local network. Nextcloud only connects to it with “allow_local_remote_servers” set to true in config.php.');
			}
			throw new ValidationException('The planning tool reported an error (%1$s)', ['unreachable']);
		}
		if ($status === 401) {
			throw new ValidationException('The planning tool rejected the login. Check the account and the app password or API key.');
		}
		if ($status >= 400 || !is_array($data)) {
			$code = match (true) {
				$status === 403 => 'forbidden',
				$status === 404 => 'not_found',
				$status === 422 => 'invalid',
				default => 'error',
			};
			$this->logger->info('nc_nexus: planning tool answered ' . $status . ' for ' . $method . ' ' . $url, ['app' => Application::APP_ID]);
			throw new ValidationException('The planning tool reported an error (%1$s)', [$code]);
		}
		return $data;
	}
}
