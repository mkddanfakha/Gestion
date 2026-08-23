<?php

namespace App\Exports\Inventory;

use App\Models\InventorySession;
use App\Services\InventoryExportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InventorySessionWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly InventorySession $session,
        private readonly InventoryExportService $exportService,
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new InventorySummarySheetExport($this->session, $this->exportService),
            new InventoryDetailSheetExport($this->session, $this->exportService),
            new InventoryVariancesSheetExport($this->session, $this->exportService),
            new InventoryMovementsSheetExport($this->session, $this->exportService),
        ];
    }
}
