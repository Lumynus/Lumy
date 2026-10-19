<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

new Config(
	driver: 'mysql',
	host: '199.193.117.186',
	user: 'testeteste',
	password: 'teste123#',
	database: 'teste',
	connectionIdentifier: 'default'
);

class TblModel extends AbstractModel
{
	public function all(): array|bool
	{
		return $this->customQuery('SELECT * FROM tbl');
	}
}

$model = new TblModel();
$rows = $model->all();

var_dump($rows);
