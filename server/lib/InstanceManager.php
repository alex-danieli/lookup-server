<?php


namespace LookupServer;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


class InstanceManager {


	/** @var PDO */
	private $db;


	/**
	 * InstanceManager constructor.
	 *
	 * @param PDO $db
	 */
	public function __construct(PDO $db) {
		$this->db = $db;
	}


	public function insert(string $instance) {
		$stmt = $this->db->prepare('SELECT id, instance, timestamp FROM instances WHERE instance=:instance');
		$stmt->bindParam(':instance', $instance, PDO::PARAM_STR);
		$stmt->execute();

		$data = $stmt->fetch();
		if ($data === false) {
			$time = time();
			$insert = $this->db->prepare(
				'INSERT INTO instances (instance, timestamp) VALUES (:instance, FROM_UNIXTIME(:timestamp))'
			);
			$insert->bindParam(':instance', $instance, PDO::PARAM_STR);
			$insert->bindParam(':timestamp', $time, PDO::PARAM_INT);

			$insert->execute();
		}
	}


	/**
	 * let Nextcloud servers obtains the full list of registered instances in the global scale scenario
	 * If result is empty, sync from the users list
	 *
	 * @param Request $request
	 * @param Response $response
	 *
	 * @return Response
	 */
	public function getInstances(Request $request, Response $response): Response {
		$instances = $this->getAll();

		if (empty($instances)) {
			$this->syncInstances();
			$instances = $this->getAll();
		}

		$response->getBody()
				 ->write(json_encode($instances));

		return $response;
	}


	/**
	 * @return array
	 */
	public function getAll(): array {
		$stmt = $this->db->prepare('SELECT instance FROM instances');
		$stmt->execute();

		$instances = [];
		while ($data = $stmt->fetch()) {
			$instances[] = $data['instance'];
		}
		$stmt->closeCursor();

		return $instances;
	}


	/**
	 * sync the instances from the users table
	 */
	public function syncInstances(): void {
		$stmt = $this->db->prepare('SELECT federationId FROM users');
		$stmt->execute();
		$instances = [];
		while ($data = $stmt->fetch()) {
			list(, $instance) = explode('@', $data['federationId'], 2);
			if (!in_array($instance, $instances)) {
				$instances[] = $instance;
			}
		}
		$stmt->closeCursor();

		foreach ($instances as $instance) {
			$this->insert($instance);
		}

		$this->removeDeprecatedInstances($instances);
	}


	/**
	 * @param string|null $instance
	 * @param bool $removeUsers
	 */
	public function remove(string $instance, bool $removeUsers = false): void {
		$stmt = $this->db->prepare('DELETE FROM instances WHERE instance = :instance');
		$stmt->bindParam(':instance', $instance);
		$stmt->execute();
		$stmt->closeCursor();

		if ($removeUsers) {
			$this->removeUsers($instance);
		}
	}


	/**
	 * @param string $instance
	 */
	private function removeUsers(string $instance) {
		$search = '%@' . $this->escapeWildcard($instance);
		$stmt = $this->db->prepare('DELETE FROM users WHERE federationId LIKE :search');
		$stmt->bindParam(':search', $search);
		$stmt->execute();
		$stmt->closeCursor();
	}


	/**
	 * @param string $input
	 *
	 * @return string
	 */
	private function escapeWildcard(string $input): string {
		$output = str_replace('%', '\%', $input);
		$output = str_replace('_', '\_', $output);

		return $output;
	}


	/**
	 * @param string $cloudId
	 */
	public function newUser(string $cloudId): void {
		list(, $instance) = explode('@', $cloudId, 2);
		$this->insert($instance);
	}


	/**
	 * @param string $cloudId
	 */
	public function removeUser(string $cloudId): void {
		list(, $instance) = explode('@', $cloudId, 2);
		$search = '%@' . $this->escapeWildcard($instance);

		$stmt = $this->db->prepare('SELECT federationId FROM users WHERE federationId LIKE :search');
		$stmt->bindParam(':search', $search);
		$stmt->execute();
		if ($stmt->fetch() === false) {
			$this->remove($instance);
		}
	}


	/**
	 * @param array $instances
	 */
	private function removeDeprecatedInstances(array $instances): void {
		$current = $this->getAll();

		foreach ($current as $item) {
			if (!in_array($item, $instances)) {
				$this->remove($item);
			}
		}
	}

}

