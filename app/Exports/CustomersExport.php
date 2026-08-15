<?php

namespace App\Exports;

use App\Models\Customer;
use App\Services\CustomerExportService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
{
    private const IDENTITY_NUMBER_COLUMN = 'K';

    protected ?string $search;

    protected CustomerExportService $exportService;

    public function __construct(?string $search = null, ?CustomerExportService $exportService = null)
    {
        $this->search = $search;
        $this->exportService = $exportService ?? app(CustomerExportService::class);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Customer>
     */
    public function collection()
    {
        return $this->exportService->getCustomers($this->search);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->exportService->excelHeadings();
    }

    /**
     * @param  Customer  $customer
     * @return list<int|string|null>
     */
    public function map($customer): array
    {
        return $this->exportService->mapCustomerForExcel($customer);
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

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $highestRow = max(2, $sheet->getHighestRow());
                $column = self::IDENTITY_NUMBER_COLUMN;

                $sheet->getStyle("{$column}2:{$column}{$highestRow}")
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);

                $sheet->setAutoFilter('A1:' . Coordinate::stringFromColumnIndex(count($this->headings())) . '1');
                $sheet->freezePane('A2');
            },
        ];
    }
}
