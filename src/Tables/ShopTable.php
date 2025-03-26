<?php

declare(strict_types=1);

namespace App\Tables;

use Swoole\Table;

final class ShopTable
{
    private Table $table;

    private Table $purchaseTable;

    private const MAX_ITEMS = 10;

    // Make this dynamic. Make no sense limits on purchase.
    private const MAX_PURCHASE = 4098;

    public function __construct(private PlayersTable $playersTable)
    {
        $this->table = new Table(self::MAX_ITEMS);

        $this->table->column('id', Table::TYPE_INT);
        $this->table->column('name', Table::TYPE_STRING, 50);
        $this->table->column('description', Table::TYPE_STRING, 1024);
        $this->table->column('image', Table::TYPE_STRING, 1024);
        $this->table->column('price', Table::TYPE_INT);

        $this->table->create();

        $this->purchaseTable = new Table(self::MAX_PURCHASE);

        $this->purchaseTable->column('userFd', Table::TYPE_INT,);
        $this->purchaseTable->column('itemId', Table::TYPE_INT);
        $this->purchaseTable->column('createdAt', Table::TYPE_INT);

        $this->purchaseTable->create();

        $this->addShopItems();
    }

    private function addShopItems(): void
    {
        $items = [
            [
                'id' => 1,
                'name' => 'Votos gigantes',
                'description' => 'Compre para aumentar o brazão ao votar.',
                'image' => 'https://placehold.co/120',
                'price' => 1,
            ],
            [
                'id' => 2,
                'name' => 'Mais votos',
                'description' => 'Seus votos agora soltam mais confetes para todos verem.',
                'image' => 'https://placehold.co/120',
                'price' => 2,
            ],
            [
                'id' => 3,
                'name' => 'AVC',
                'description' => 'Simplesmente compra e morra.',
                'image' => 'AVC',
                'price' => 3,
            ],
            [
                'id' => 4,
                'name' => 'Emojis',
                'description' => 'Liberados para usar no final da partida.',
                'image' => 'https://placehold.co/120',
                'price' => 4,
            ],
            [
                'id' => 5,
                'name' => 'ADM',
                'description' => 'Torne-se administrador do servidor.',
                'image' => 'ADM',
                'price' => 5,
            ],
        ];

        foreach ($items as $item) {
            $this->table->set((string) $item['id'], $item);
        }
    }

    private function isPurchased(int $playerFd, int $itemId): bool
    {
        foreach ($this->purchaseTable as $row) {
            if ($row['userFd'] !== $playerFd || $row['itemId'] !== $itemId) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function get(?int $playerFd = null): array
    {
        $result = [];

        foreach ($this->table as $row) {
            $item = $row;

            if ($playerFd) {
                $item['purchased'] = $this->isPurchased($playerFd, $row['id']);
            }

            $result[] = $item;
        }

        return $result;
    }

    public function purchase(int $playerFd, int $itemId): bool
    {
        $item = $this->table->get((string) $itemId);

        $response = $this->playersTable->removeBalance($playerFd, $item['price']);

        if (! $response) {
            return false;
        }

        $this->purchaseTable->set("{$playerFd}-{$itemId}", [
            'userFd' => $playerFd,
            'itemId' => $itemId,
            'createdAt' => time(),
        ]);

        return true;
    }
}
