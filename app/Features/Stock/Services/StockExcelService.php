<?php

namespace App\Features\Stock\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockExcelService
{
    public function __construct(
        protected StockRepositoryInterface $stockRepository
    ) {}

    /**
     * Build a Spreadsheet instance containing stock data.
     *
     * @param Collection<int, Stock> $stocks
     */
    public function buildSpreadsheet(Collection $stocks, string $sheetTitle = 'Stocks'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($sheetTitle, 0, 31));

        $headers = ['No', 'Name', 'SKU', 'Description', 'Quantity', 'Unit', 'Price', 'Min Stock', 'Status', 'Created At'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue("{$columns[$index]}1", $header);
        }

        // Header Styling
        $headerRange = 'A1:J1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNumber = 2;
        foreach ($stocks as $idx => $stock) {
            $sheet->setCellValue("A{$rowNumber}", $idx + 1);
            $sheet->setCellValue("B{$rowNumber}", $stock->name);
            $sheet->setCellValue("C{$rowNumber}", $stock->sku ?? '-');
            $sheet->setCellValue("D{$rowNumber}", $stock->description ?? '-');
            $sheet->setCellValue("E{$rowNumber}", $stock->quantity);
            $sheet->setCellValue("F{$rowNumber}", $stock->unit);
            $sheet->setCellValue("G{$rowNumber}", $stock->price ?? '-');
            $sheet->setCellValue("H{$rowNumber}", $stock->min_stock);
            $sheet->setCellValue("I{$rowNumber}", $stock->status);
            $sheet->setCellValue("J{$rowNumber}", $stock->created_at ? $stock->created_at->format('Y-m-d H:i') : '-');
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->getStyle("A1:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Export stocks as a downloadable streamed response.
     *
     * @param Collection<int, Stock> $stocks
     */
    public function exportToResponse(Collection $stocks, string $filename = 'stock.xlsx'): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($stocks);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Import stocks from an uploaded spreadsheet file path.
     * Kolom: A No, B Name, C SKU, D Description, E Quantity, F Unit, G Price, H Min Stock, I Status.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public function import(string $filePath, int $userId): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $imported = 0;
        $errors = [];
        $recordsToInsert = [];
        $now = Carbon::now();

        // Skip header row
        $isFirst = true;

        foreach ($rows as $row) {
            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            $name = isset($row['B']) ? trim((string) $row['B']) : '';

            if ($name === '') {
                continue;
            }

            $sku = isset($row['C']) ? trim((string) $row['C']) : '';
            $description = isset($row['D']) ? trim((string) $row['D']) : '';
            $quantity = isset($row['E']) && is_numeric($row['E']) ? max(0, (int) $row['E']) : 0;
            $unit = isset($row['F']) && trim((string) $row['F']) !== '' ? trim((string) $row['F']) : 'pcs';
            $price = isset($row['G']) && is_numeric($row['G']) ? max(0, (float) $row['G']) : null;
            $minStock = isset($row['H']) && is_numeric($row['H']) ? max(0, (int) $row['H']) : 5;
            $statusRaw = isset($row['I']) ? strtolower(trim((string) $row['I'])) : '';

            $validStatuses = array_map(fn (StockStatus $status) => $status->value, StockStatus::cases());

            $status = in_array($statusRaw, $validStatuses, true)
                ? $statusRaw
                : StockStatus::fromQuantity($quantity, $minStock)->value;

            $recordsToInsert[] = [
                'user_id' => $userId,
                'name' => substr($name, 0, 255),
                'sku' => ($sku === '' || $sku === '-') ? null : substr($sku, 0, 255),
                'description' => ($description === '' || $description === '-') ? null : $description,
                'quantity' => $quantity,
                'unit' => substr($unit, 0, 255),
                'price' => $price,
                'min_stock' => $minStock,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $imported++;
        }

        if (!empty($recordsToInsert)) {
            $this->stockRepository->insertMany($recordsToInsert);
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
