<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockExcelService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StockExcelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockExcelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockExcelService(new StockRepository());
    }

    public function test_build_spreadsheet_and_export_response(): void
    {
        $user = User::factory()->create();
        $stocks = Stock::factory()->count(2)->create(['user_id' => $user->id]);

        $spreadsheet = $this->service->buildSpreadsheet($stocks, 'Custom Stocks');
        $this->assertInstanceOf(Spreadsheet::class, $spreadsheet);
        $this->assertEquals('Custom Stocks', $spreadsheet->getActiveSheet()->getTitle());

        $response = $this->service->exportToResponse($stocks, 'test.xlsx');
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        ob_start();
        $response->sendContent();
        $outputContent = ob_get_clean();
        $this->assertNotEmpty($outputContent);
    }

    public function test_build_spreadsheet_handles_null_optional_fields(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'sku' => null,
            'description' => null,
            'price' => null,
        ]);

        $spreadsheet = $this->service->buildSpreadsheet(Stock::query()->whereKey($stock->id)->get());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('Name', $sheet->getCell('B1')->getValue());
        $this->assertEquals('-', $sheet->getCell('C2')->getValue());
        $this->assertEquals('-', $sheet->getCell('G2')->getValue());
    }

    public function test_import_spreadsheet(): void
    {
        $user = User::factory()->create();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['No', 'Name', 'SKU', 'Description', 'Quantity', 'Unit', 'Price', 'Min Stock', 'Status'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }

        $rows = [
            // Row 2: data lengkap, status eksplisit
            ['1', 'Imported Stock 1', 'IMP-1', 'Desc 1', '2', 'box', '1500.5', '5', 'available'],
            // Row 3: status tidak valid -> dihitung dari quantity; nilai kosong/'-' -> default
            ['2', 'Imported Stock 2', '-', '-', '0', '', 'abc', 'x', 'unknown_status'],
            // Row 4: quantity besar, status kosong -> available
            ['3', 'Imported Stock 3', 'IMP-3', 'Desc 3', '100', 'kg', '', '10', ''],
            // Row 5: nama kosong -> dilewati
            ['4', '', 'IMP-4', 'Skip this', '1', 'pcs', '1', '1', ''],
        ];
        foreach ($rows as $r => $row) {
            foreach ($row as $i => $value) {
                $sheet->setCellValue(chr(65 + $i).($r + 2), $value);
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'test_excel_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $result = $this->service->import($tempPath, $user->id);
        @unlink($tempPath);

        $this->assertEquals(3, $result['imported']);
        $this->assertSame([], $result['errors']);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Imported Stock 1',
            'sku' => 'IMP-1',
            'quantity' => 2,
            'unit' => 'box',
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Imported Stock 2',
            'sku' => null,
            'description' => null,
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => null,
            'min_stock' => 5,
            'status' => 'out_of_stock',
        ]);
        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Imported Stock 3',
            'quantity' => 100,
            'status' => 'available',
        ]);
    }

    public function test_import_empty_sheet_returns_zero(): void
    {
        $user = User::factory()->create();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'No');

        $tempPath = tempnam(sys_get_temp_dir(), 'test_excel_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $result = $this->service->import($tempPath, $user->id);
        @unlink($tempPath);

        $this->assertEquals(0, $result['imported']);
        $this->assertDatabaseCount('stocks', 0);
    }
}
