<?php

namespace App\Exports;

use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SuppliersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $search;
    protected $status;

    public function __construct($search = null, $status = null)
    {
        $this->search = $search;
        $this->status = $status;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = Supplier::query();

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ID',
            'Nom',
            'Personne de contact',
            'Email',
            'Téléphone',
            'Mobile',
            'Adresse',
            'Ville',
            'Pays',
            'Numéro fiscal',
            'Statut',
            'Notes',
        ];
    }

    /**
     * @param Supplier $supplier
     * @return array
     */
    public function map($supplier): array
    {
        return [
            $supplier->id,
            $supplier->name,
            $supplier->contact_person ?? '',
            $supplier->email ?? '',
            $supplier->phone ?? '',
            $supplier->mobile ?? '',
            $supplier->address ?? '',
            $supplier->city ?? '',
            $supplier->country ?? '',
            $supplier->tax_id ?? '',
            $supplier->status === 'active' ? 'Actif' : 'Inactif',
            $supplier->notes ?? '',
        ];
    }

    /**
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
