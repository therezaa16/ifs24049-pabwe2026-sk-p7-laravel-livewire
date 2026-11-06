<?php

namespace Tests\Feature\Stock;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Ai\DTO\GeneratedStockItem;
use App\Features\Stock\Livewire\StockList;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StockListLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_stocks(): void
    {
        $this->get('/stocks')->assertRedirect(route('login'));
    }

    public function test_member_can_view_own_stocks(): void
    {
        $user = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Barang Spesifik Member']);

        $otherUser = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $otherUser->id, 'name' => 'Barang Rahasia Orang Lain']);

        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->assertSee('Barang Spesifik Member')
            ->assertDontSee('Barang Rahasia Orang Lain');
    }

    public function test_create_stock_validation_and_success(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        // Validation error
        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', '')
            ->set('formQuantity', '-1')
            ->set('formStatus', 'invalid_status')
            ->call('saveStock')
            ->assertHasErrors(['formName', 'formQuantity', 'formStatus']);

        // Success
        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', 'Stock Baru')
            ->set('formSku', 'SKU-NEW')
            ->set('formDescription', 'Deskripsi stock')
            ->set('formQuantity', '12')
            ->set('formUnit', 'box')
            ->set('formPrice', '15000')
            ->set('formMinStock', '4')
            ->set('formStatus', 'available')
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Stock Baru',
            'sku' => 'SKU-NEW',
            'description' => 'Deskripsi stock',
            'quantity' => 12,
            'unit' => 'box',
            'min_stock' => 4,
        ]);
    }

    public function test_edit_stock_success(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Judul Lama', 'price' => 2500]);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('editingStockId', $stock->id)
            ->assertSet('formName', 'Judul Lama')
            ->assertSet('formPrice', '2500.00')
            ->set('formName', 'Judul Telah Diedit')
            ->set('formPrice', null)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'name' => 'Judul Telah Diedit',
            'price' => null,
        ]);
    }

    public function test_edit_modal_handles_stock_without_price(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id, 'price' => null]);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('formPrice', null);
    }

    public function test_form_status_follows_quantity_and_min_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->assertSet('formStatus', 'out_of_stock')
            ->set('formMinStock', '5')
            ->set('formQuantity', '3')
            ->assertSet('formStatus', 'low_stock')
            ->set('formQuantity', '50')
            ->assertSet('formStatus', 'available')
            ->set('formQuantity', '0')
            ->assertSet('formStatus', 'out_of_stock')
            ->set('formQuantity', 'abc')
            ->assertSet('formStatus', 'out_of_stock');
    }

    public function test_adjust_quantity_syncs_status(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'quantity' => 6,
            'min_stock' => 5,
            'status' => 'available',
        ]);

        $component = Livewire::test(StockList::class)->call('adjustQuantity', $stock->id, -1);
        $this->assertSame('low_stock', $stock->fresh()->status);
        $this->assertSame(5, $stock->fresh()->quantity);

        $component->call('adjustQuantity', $stock->id, -10);
        $this->assertSame('out_of_stock', $stock->fresh()->status);
        $this->assertSame(0, $stock->fresh()->quantity);

        $component->call('adjustQuantity', $stock->id, 20);
        $this->assertSame('available', $stock->fresh()->status);
        $this->assertSame(20, $stock->fresh()->quantity);
    }

    public function test_cannot_adjust_quantity_of_other_users_stock(): void
    {
        $user = User::factory()->member()->create();
        $other = Stock::factory()->create(['quantity' => 10]);
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('adjustQuantity', $other->id, 1)
            ->assertForbidden();

        $this->assertSame(10, $other->fresh()->quantity);
    }

    public function test_delete_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id]);

        Livewire::test(StockList::class)
            ->call('deleteStock', $stock->id);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_confirm_and_cancel_delete_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id]);

        Livewire::test(StockList::class)
            ->assertSet('confirmingStockDeletionId', null)
            ->call('confirmDelete', $stock->id)
            ->assertSet('confirmingStockDeletionId', $stock->id)
            ->call('cancelDelete')
            ->assertSet('confirmingStockDeletionId', null)
            ->call('confirmDelete', $stock->id)
            ->call('deleteStock')
            ->assertSet('confirmingStockDeletionId', null);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_search_and_status_filter(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Makan Siang', 'sku' => 'SKU-SIANG', 'status' => 'available']);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Makan Malam', 'sku' => 'SKU-MALAM', 'status' => 'low_stock']);

        Livewire::test(StockList::class)
            ->set('search', 'Siang')
            ->assertSee('Makan Siang')
            ->assertDontSee('Makan Malam')
            ->set('search', 'SKU-MALAM')
            ->assertSee('Makan Malam')
            ->assertDontSee('Makan Siang')
            ->set('search', '')
            ->set('statusFilter', 'low_stock')
            ->assertSee('Makan Malam')
            ->assertDontSee('Makan Siang');
    }

    public function test_export_excel(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Exportable Stock']);

        Livewire::test(StockList::class)
            ->call('exportExcel')
            ->assertFileDownloaded();
    }

    public function test_import_excel(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['No', 'Name', 'SKU', 'Description', 'Quantity', 'Unit', 'Price', 'Min Stock', 'Status'] as $i => $h) {
            $sheet->setCellValue(chr(65 + $i).'1', $h);
        }

        $rows = [
            ['1', 'Imported Excel Stock', 'XLS-1', 'Excel Desc', '2', 'box', '1500', '5', ''],
            ['2', 'Imported Full Stock', '-', '-', '40', '', 'abc', 'x', 'available'],
            ['3', '', 'XLS-3', 'Baris tanpa nama dilewati', '1', 'pcs', '1', '1', ''],
        ];
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $value) {
                $sheet->setCellValue(chr(65 + $i).($r + 2), $value);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'test_upload_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $fileContent = file_get_contents($tempPath);
        @unlink($tempPath);

        $uploadedFile = UploadedFile::fake()->createWithContent('stocks.xlsx', $fileContent);

        Livewire::test(StockList::class)
            ->call('openImportModal')
            ->assertSet('showImportModal', true)
            ->set('importFile', $uploadedFile)
            ->call('importExcel')
            ->assertSet('showImportModal', false);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Imported Excel Stock',
            'sku' => 'XLS-1',
            'description' => 'Excel Desc',
            'quantity' => 2,
            'status' => 'low_stock',
        ]);
        $this->assertDatabaseHas('stocks', [
            'name' => 'Imported Full Stock',
            'sku' => null,
            'unit' => 'pcs',
            'min_stock' => 5,
            'status' => 'available',
        ]);
        $this->assertDatabaseMissing('stocks', ['sku' => 'XLS-3']);
    }

    public function test_ai_generation_and_saving(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        // Mock AiClientInterface
        $mockAiClient = $this->createStub(AiClientInterface::class);
        $mockAiClient->method('generateStocks')->willReturn([
            new GeneratedStockItem('AI Task 1', 'AI-1', 'Desc 1', 30, 'box', 12000.0, 5),
            new GeneratedStockItem('AI Task 2'),
        ]);
        $this->app->instance(AiClientInterface::class, $mockAiClient);

        // Test validation for prompt and count
        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', '')
            ->set('aiCount', 0)
            ->call('generateWithAi')
            ->assertHasErrors(['aiPrompt', 'aiCount']);

        $component = Livewire::test(StockList::class)
            ->call('openAiModal')
            ->assertSet('showAiModal', true)
            ->set('aiPrompt', 'Buatkan daftar perlengkapan kantor')
            ->set('aiCount', 2)
            ->call('generateWithAi')
            ->assertCount('aiGeneratedItems', 2)
            ->assertCount('aiSelectedIndices', 2);

        // Try saving with no items selected
        $component->set('aiSelectedIndices', [])
            ->call('saveAiGenerated')
            ->assertSet('aiErrorMessage', 'Pilih minimal satu item stok yang ingin disimpan.');

        // Save selected items
        $component->set('aiSelectedIndices', [0, 1])
            ->call('saveAiGenerated')
            ->assertSet('showAiModal', false);

        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'name' => 'AI Task 1', 'quantity' => 30, 'status' => 'available']);
        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'name' => 'AI Task 2', 'quantity' => 0, 'status' => 'out_of_stock']);
    }

    public function test_ai_generation_handles_exception(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $mockAiClient = $this->createStub(AiClientInterface::class);
        $mockAiClient->method('generateStocks')->willThrowException(new \RuntimeException('API quota exceeded'));
        $this->app->instance(AiClientInterface::class, $mockAiClient);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', 'Error test')
            ->call('generateWithAi')
            ->assertSet('aiErrorMessage', 'API quota exceeded');
    }

    public function test_close_modals(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->call('closeFormModal')
            ->assertSet('showFormModal', false)
            ->call('openImportModal')
            ->call('closeImportModal')
            ->assertSet('showImportModal', false)
            ->call('openAiModal')
            ->call('closeAiModal')
            ->assertSet('showAiModal', false);
    }
}
