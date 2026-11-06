<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $service;
    protected StockRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new StockRepository();
        $this->service = new StockService($this->repository);
    }

    public function test_get_stocks_for_user(): void
    {
        $user = User::factory()->create();
        Stock::factory()->count(2)->create(['user_id' => $user->id]);

        $paginator = $this->service->getStocksForUser($user->id);
        $this->assertCount(2, $paginator);
    }

    public function test_create_stock_with_explicit_values(): void
    {
        $user = User::factory()->create();
        $stock = $this->service->createStock($user->id, [
            'name' => 'Service Stock',
            'sku' => 'SRV-1',
            'description' => 'Desc',
            'quantity' => 20,
            'unit' => 'box',
            'price' => 9900,
            'min_stock' => 4,
            'status' => 'available',
        ]);

        $this->assertInstanceOf(Stock::class, $stock);
        $this->assertEquals('Service Stock', $stock->name);
        $this->assertEquals('SRV-1', $stock->sku);
        $this->assertEquals(20, $stock->quantity);
        $this->assertEquals('box', $stock->unit);
        $this->assertEquals('available', $stock->status);
    }

    public function test_create_stock_defaults_and_auto_status(): void
    {
        $user = User::factory()->create();

        $empty = $this->service->createStock($user->id, ['name' => 'Kosong']);
        $this->assertEquals(0, $empty->quantity);
        $this->assertEquals('pcs', $empty->unit);
        $this->assertEquals(5, $empty->min_stock);
        $this->assertEquals('out_of_stock', $empty->status);

        $low = $this->service->createStock($user->id, ['name' => 'Menipis', 'quantity' => 3]);
        $this->assertEquals('low_stock', $low->status);

        $ok = $this->service->createStock($user->id, ['name' => 'Aman', 'quantity' => 30]);
        $this->assertEquals('available', $ok->status);
    }

    public function test_update_stock(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

        $updated = $this->service->updateStock($stock, [
            'name' => 'New Name',
            'description' => 'Updated Desc',
            'quantity' => 7,
            'status' => StockStatus::LOW_STOCK->value,
        ]);

        $this->assertTrue($updated);
        $stock->refresh();
        $this->assertEquals('New Name', $stock->name);
        $this->assertEquals('Updated Desc', $stock->description);
        $this->assertEquals(7, $stock->quantity);
        $this->assertEquals('low_stock', $stock->status);
    }

    public function test_update_stock_can_clear_nullable_fields(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'sku' => 'KEEP', 'price' => 100]);

        $this->service->updateStock($stock, ['sku' => null, 'price' => null, 'unit' => 'kg', 'min_stock' => 9]);
        $stock->refresh();

        $this->assertNull($stock->sku);
        $this->assertNull($stock->price);
        $this->assertEquals('kg', $stock->unit);
        $this->assertEquals(9, $stock->min_stock);
    }

    public function test_delete_stock(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $deleted = $this->service->deleteStock($stock);
        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_adjust_quantity_syncs_status_and_never_goes_negative(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'quantity' => 6,
            'min_stock' => 5,
            'status' => 'available',
        ]);

        $this->service->adjustQuantity($stock, -1);
        $stock->refresh();
        $this->assertEquals(5, $stock->quantity);
        $this->assertEquals(StockStatus::LOW_STOCK->value, $stock->status);

        $this->service->adjustQuantity($stock, -50);
        $stock->refresh();
        $this->assertEquals(0, $stock->quantity);
        $this->assertEquals(StockStatus::OUT_OF_STOCK->value, $stock->status);

        $this->service->adjustQuantity($stock, 10);
        $stock->refresh();
        $this->assertEquals(10, $stock->quantity);
        $this->assertEquals(StockStatus::AVAILABLE->value, $stock->status);
    }

    public function test_cover_photo_management(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $fakeFile = \Illuminate\Http\UploadedFile::fake()->image('cover.jpg');

        // Update cover
        $path = $this->service->updateCoverPhoto($stock, $fakeFile);
        $stock->refresh();
        $this->assertEquals($path, $stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);

        // Update cover again (replaces old)
        $newFakeFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');
        $newPath = $this->service->updateCoverPhoto($stock, $newFakeFile);
        $stock->refresh();
        $this->assertEquals($newPath, $stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($newPath);

        // Delete cover
        $deleted = $this->service->deleteCoverPhoto($stock);
        $this->assertTrue($deleted);
        $stock->refresh();
        $this->assertNull($stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($newPath);

        // Delete stock cleans up cover photo if present
        $anotherFile = \Illuminate\Http\UploadedFile::fake()->image('another.jpg');
        $anotherPath = $this->service->updateCoverPhoto($stock, $anotherFile);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($anotherPath);

        $this->service->deleteStock($stock);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($anotherPath);
    }
}

