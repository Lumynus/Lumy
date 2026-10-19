<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;
use Lumynus\Lumy\Model;

new Config(
    driver: 'sqlite',
    database: ':memory:',
    connectionIdentifier: 'default'
);

class ProductModel extends AbstractModel
{
    protected string $table = 'products';

    protected array $fields = [
        'id' => ['db' => 'products.id', 'select' => true],
        'sku' => ['db' => 'products.sku', 'select' => true],
        'product_name' => ['db' => 'products.product_name', 'select' => true],
        'unit_price' => ['db' => 'products.unit_price', 'select' => true],
        'stock_qty' => ['db' => 'products.stock_qty', 'select' => true],
        'is_active' => ['db' => 'products.is_active', 'select' => true],
    ];

    public function createSchema(): void
    {
        $this->customQuery(
            'CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT NOT NULL UNIQUE,
                product_name TEXT NOT NULL,
                unit_price REAL NOT NULL,
                stock_qty INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1
            )'
        );
    }
}

$products = new ProductModel();
$products->createSchema();

// 1. INSERT
$id1 = $products->insert([
    'sku' => 'NB-PRO-15',
    'product_name' => 'Notebook Pro 15',
    'unit_price' => 6499.90,
    'stock_qty' => 10,
    'is_active' => true,
]);

$id2 = $products->insert([
    'sku' => 'MON-4K-27',
    'product_name' => 'Monitor 4K 27"',
    'unit_price' => 2199.00,
    'stock_qty' => 4,
    'is_active' => true,
]);

echo "=== 1. Apos INSERT (IDs {$id1} e {$id2}) ===" . PHP_EOL;
var_export($products->select(camelCaseTransform: true));
echo PHP_EOL . PHP_EOL;

// 2. UPSERT (atualiza SKU existente e insere novo SKU)
$products->upsert(
    [
        'sku' => 'NB-PRO-15',
        'product_name' => 'Notebook Pro 15 (Edicao 2026)',
        'unit_price' => 6299.90,
        'stock_qty' => 15,
        'is_active' => true,
    ],
    ['sku']
);

$products->upsert(
    [
        'sku' => 'HEAD-ANC-01',
        'product_name' => 'Headset Bluetooth ANC',
        'unit_price' => 899.00,
        'stock_qty' => 25,
        'is_active' => true,
    ],
    ['sku']
);

echo "=== 2. Apos UPSERT por SKU ===" . PHP_EOL;
var_export($products->select(orderBy: 'id ASC', camelCaseTransform: true));
echo PHP_EOL . PHP_EOL;

// 3. UPDATE com array de condicoes e com expressao SQL customizada
$affected1 = $products->update(
    ['stock_qty' => 2],
    ['sku' => 'MON-4K-27']
);

$affected2 = $products->update(
    ['is_active' => false],
    'WHERE stock_qty <= ? AND unit_price > ?',
    [5, 1000]
);

echo "=== 3. Apos UPDATE (afetados: {$affected1} e {$affected2}) ===" . PHP_EOL;
var_export($products->select(orderBy: 'id ASC', camelCaseTransform: true));
echo PHP_EOL . PHP_EOL;

// 4. DELETE e consulta unitaria via Model::get()
$deleted = $products->delete(['is_active' => false]);
echo "=== 4. Apos DELETE de inativos (removidos: {$deleted}) ===" . PHP_EOL;
$quickModel = new Model('products');
var_export($quickModel->get(['sku' => 'HEAD-ANC-01'], camelCaseTransform: true));
echo PHP_EOL;

