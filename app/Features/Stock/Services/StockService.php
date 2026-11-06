<?php

namespace App\Features\Stock\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

class StockService
{
    public function __construct(
        protected StockRepositoryInterface $stockRepository
    ) {}

    /**
     * Get paginated stocks for the specified user.
     */
    public function getStocksForUser(int $userId, ?string $status = null, ?string $search = null, int $perPage = 10): LengthAwarePaginator
    {
        return $this->stockRepository->forUser($userId, $status, $search, $perPage);
    }

    /**
     * Create a new stock item for the user.
     *
     * @param array<string, mixed> $data
     */
    public function createStock(int $userId, array $data): Stock
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        $minStock = (int) ($data['min_stock'] ?? 5);

        return $this->stockRepository->create([
            'user_id' => $userId,
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'quantity' => $quantity,
            'unit' => $data['unit'] ?? 'pcs',
            'price' => $data['price'] ?? null,
            'min_stock' => $minStock,
            'status' => $data['status'] ?? StockStatus::fromQuantity($quantity, $minStock)->value,
            'cover_image_path' => $data['cover_image_path'] ?? null,
        ]);
    }

    /**
     * Update an existing stock item.
     *
     * @param array<string, mixed> $data
     */
    public function updateStock(Stock $stock, array $data): bool
    {
        return $this->stockRepository->update($stock, [
            'name' => $data['name'] ?? $stock->name,
            'sku' => array_key_exists('sku', $data) ? $data['sku'] : $stock->sku,
            'description' => array_key_exists('description', $data) ? $data['description'] : $stock->description,
            'quantity' => $data['quantity'] ?? $stock->quantity,
            'unit' => $data['unit'] ?? $stock->unit,
            'price' => array_key_exists('price', $data) ? $data['price'] : $stock->price,
            'min_stock' => $data['min_stock'] ?? $stock->min_stock,
            'status' => $data['status'] ?? $stock->status,
            'cover_image_path' => array_key_exists('cover_image_path', $data) ? $data['cover_image_path'] : $stock->cover_image_path,
        ]);
    }

    /**
     * Update or upload a cover photo for the stock.
     */
    public function updateCoverPhoto(Stock $stock, mixed $file): string
    {
        if ($stock->cover_image_path && Storage::disk('public')->exists($stock->cover_image_path)) {
            Storage::disk('public')->delete($stock->cover_image_path);
        }

        $path = $file->store('stock-covers', 'public');
        $this->updateStock($stock, ['cover_image_path' => $path]);

        return $path;
    }

    /**
     * Delete the cover photo of the stock.
     */
    public function deleteCoverPhoto(Stock $stock): bool
    {
        if ($stock->cover_image_path && Storage::disk('public')->exists($stock->cover_image_path)) {
            Storage::disk('public')->delete($stock->cover_image_path);
        }

        return $this->updateStock($stock, ['cover_image_path' => null]);
    }

    /**
     * Delete a stock item and its cover photo if exists.
     */
    public function deleteStock(Stock $stock): bool
    {
        if ($stock->cover_image_path && Storage::disk('public')->exists($stock->cover_image_path)) {
            Storage::disk('public')->delete($stock->cover_image_path);
        }

        return $this->stockRepository->delete($stock);
    }

    /**
     * Tambah / kurangi quantity (delta positif atau negatif) dan sinkronkan status.
     * Quantity tidak pernah di bawah 0.
     */
    public function adjustQuantity(Stock $stock, int $delta): bool
    {
        $newQuantity = max(0, (int) $stock->quantity + $delta);

        return $this->stockRepository->update($stock, [
            'quantity' => $newQuantity,
            'status' => StockStatus::fromQuantity($newQuantity, (int) $stock->min_stock)->value,
        ]);
    }
}
