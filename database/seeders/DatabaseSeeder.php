<?php

namespace Database\Seeders;

use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => Hash::make('admin'),
                'role' => 'admin',
            ]
        );

        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            [
                'name' => 'Member User',
                'username' => 'member',
                'password' => Hash::make('member'),
                'role' => 'member',
            ]
        );

        $items = [
            ['name' => 'Kertas HVS A4 80gsm', 'sku' => 'ATK-001', 'description' => 'Kertas HVS untuk kebutuhan cetak kantor.', 'quantity' => 120, 'unit' => 'rim', 'price' => 52000, 'min_stock' => 20, 'status' => 'available'],
            ['name' => 'Pulpen Gel Hitam', 'sku' => 'ATK-002', 'description' => 'Pulpen gel 0.5mm warna hitam.', 'quantity' => 8, 'unit' => 'pcs', 'price' => 4500, 'min_stock' => 10, 'status' => 'low_stock'],
            ['name' => 'Tinta Printer Hitam', 'sku' => 'ATK-003', 'description' => 'Botol tinta printer 65ml.', 'quantity' => 0, 'unit' => 'botol', 'price' => 85000, 'min_stock' => 3, 'status' => 'out_of_stock'],
            ['name' => 'Map Folder Plastik', 'sku' => 'ATK-004', 'description' => 'Map folder plastik ukuran folio.', 'quantity' => 60, 'unit' => 'pcs', 'price' => 3000, 'min_stock' => 15, 'status' => 'available'],
        ];

        foreach ($items as $item) {
            Stock::firstOrCreate(
                ['user_id' => $member->id, 'sku' => $item['sku']],
                $item
            );
        }
    }
}
