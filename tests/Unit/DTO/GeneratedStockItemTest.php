<?php

namespace Tests\Unit\DTO;

use App\Features\Ai\DTO\GeneratedStockItem;
use PHPUnit\Framework\TestCase;

class GeneratedStockItemTest extends TestCase
{
    public function test_constructor_and_to_array(): void
    {
        $dto = new GeneratedStockItem(
            name: 'Kertas HVS A4',
            sku: 'ATK-001',
            description: 'Kertas 80gsm',
            quantity: 40,
            unit: 'rim',
            price: 52000.0,
            minStock: 10,
        );

        $this->assertEquals('Kertas HVS A4', $dto->name);
        $this->assertEquals(40, $dto->quantity);

        $this->assertEquals([
            'name' => 'Kertas HVS A4',
            'sku' => 'ATK-001',
            'description' => 'Kertas 80gsm',
            'quantity' => 40,
            'unit' => 'rim',
            'price' => 52000.0,
            'min_stock' => 10,
        ], $dto->toArray());
    }

    public function test_defaults(): void
    {
        $dto = new GeneratedStockItem('Item');

        $this->assertNull($dto->sku);
        $this->assertNull($dto->price);
        $this->assertSame(0, $dto->quantity);
        $this->assertSame('pcs', $dto->unit);
        $this->assertSame(5, $dto->minStock);
    }
}
