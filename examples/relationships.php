<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Lumynus\Lumy\AbstractModel;
use Lumynus\Lumy\Config;

new Config(
    driver: 'sqlite',
    database: ':memory:',
    connectionIdentifier: 'default'
);

class CustomerAddressModel extends AbstractModel
{
    protected string $table = 'customer_addresses';

    protected array $fields = [
        'id' => ['db' => 'customer_addresses.id', 'select' => true],
        'customer_id' => ['db' => 'customer_addresses.customer_id', 'select' => true],
        'city_name' => ['db' => 'customer_addresses.city_name', 'select' => true],
        'state_code' => ['db' => 'customer_addresses.state_code', 'select' => true],
    ];
}

class OrderItemModel extends AbstractModel
{
    protected string $table = 'order_items';

    protected array $fields = [
        'id' => ['db' => 'order_items.id', 'select' => true],
        'order_id' => ['db' => 'order_items.order_id', 'select' => true],
        'item_name' => ['db' => 'order_items.item_name', 'select' => true],
        'quantity' => ['db' => 'order_items.quantity', 'select' => true],
    ];
}

class OrderModel extends AbstractModel
{
    protected string $table = 'orders';

    protected array $fields = [
        'id' => ['db' => 'orders.id', 'select' => true],
        'customer_id' => ['db' => 'orders.customer_id', 'select' => true],
        'order_code' => ['db' => 'orders.order_code', 'select' => true],
        'total_amount' => ['db' => 'orders.total_amount', 'select' => true],
    ];

    protected array $relationships = [
        'items' => [
            'model' => OrderItemModel::class,
            'localKey' => 'id',
            'foreignKey' => 'order_id',
            'many' => true,
        ],
    ];
}

class CustomerModel extends AbstractModel
{
    protected string $table = 'customers';

    protected array $fields = [
        'id' => ['db' => 'customers.id', 'select' => true],
        'customer_name' => ['db' => 'customers.customer_name', 'select' => true],
    ];

    protected array $relationships = [
        'address' => [
            'model' => CustomerAddressModel::class,
            'localKey' => 'id',
            'foreignKey' => 'customer_id',
            'many' => false,
        ],
        'orders' => [
            'model' => OrderModel::class,
            'localKey' => 'id',
            'foreignKey' => 'customer_id',
            'many' => true,
        ],
    ];

    public function seed(): void
    {
        $this->customQuery('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_name TEXT NOT NULL)');
        $this->customQuery('CREATE TABLE customer_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, city_name TEXT NOT NULL, state_code TEXT NOT NULL)');
        $this->customQuery('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER NOT NULL, order_code TEXT NOT NULL, total_amount REAL NOT NULL)');
        $this->customQuery('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, item_name TEXT NOT NULL, quantity INTEGER NOT NULL)');

        $customerId = $this->insert(['customer_name' => 'Mariana Oliveira']);
        (new CustomerAddressModel())->insert([
            'customer_id' => $customerId,
            'city_name' => 'Sao Paulo',
            'state_code' => 'SP',
        ]);

        $orderModel = new OrderModel();
        $itemModel = new OrderItemModel();

        $order1 = $orderModel->insert([
            'customer_id' => $customerId,
            'order_code' => 'PED-2026-001',
            'total_amount' => 450.00,
        ]);
        $itemModel->insert(['order_id' => $order1, 'item_name' => 'Teclado Mecanico', 'quantity' => 1]);
        $itemModel->insert(['order_id' => $order1, 'item_name' => 'Mousepad XL', 'quantity' => 2]);

        $order2 = $orderModel->insert([
            'customer_id' => $customerId,
            'order_code' => 'PED-2026-002',
            'total_amount' => 120.00,
        ]);
        $itemModel->insert(['order_id' => $order2, 'item_name' => 'Cabo USB-C', 'quantity' => 3]);
    }
}

$customers = new CustomerModel();
$customers->seed();

echo "=== Clientes com Endereco (1:1), Pedidos (1:N) e Itens do Pedido (Aninhado) ===" . PHP_EOL;
$data = $customers->select(
    camelCaseTransform: true,
    with: [
        'address',
        'orders' => ['items'],
    ]
);

var_export($data);
echo PHP_EOL;

