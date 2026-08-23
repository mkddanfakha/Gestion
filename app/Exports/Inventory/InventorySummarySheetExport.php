<?php

namespace App\Exports\Inventory;

use App\Models\InventorySession;
use App\Services\InventoryExportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventorySummarySheetExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly InventorySession $session,
        private readonly InventoryExportService $exportService,
    ) {}

    public function title(): string
    {
        return 'Résumé';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->exportService->summarySheetHeadings();
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function array(): array
    {
        return $this->exportService->summarySheetRows($this->session);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
