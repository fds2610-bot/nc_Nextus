<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'demand_api#index', 'url' => '/api/v1/demands', 'verb' => 'GET'],
		['name' => 'demand_api#show', 'url' => '/api/v1/demands/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'demand_api#create', 'url' => '/api/v1/demands', 'verb' => 'POST'],
		['name' => 'demand_api#update', 'url' => '/api/v1/demands/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'demand_api#submit', 'url' => '/api/v1/demands/{id}/submit', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'demand_api#destroy', 'url' => '/api/v1/demands/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'demand_api#history', 'url' => '/api/v1/demands/{id}/history', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
	],
];
