<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $search;

    public function __construct($search = null)
    {
        $this->search = $search;
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = Customer::query()->withCount('sales');

        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
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
            'Email',
            'Téléphone',
            'Adresse',
            'Ville',
            'Code postal',
            'Pays',
            'Limite de crédit',
            'Statut',
            'Nombre de ventes',
        ];
    }

    /**
     * @param Customer $customer
     * @return array
     */
    public function map($customer): array
    {
        return [
            $customer->id,
            $customer->name,
            $customer->email ?? '',
            $customer->phone ?? '',
            $customer->address ?? '',
            $customer->city ?? '',
            $customer->postal_code ?? '',
            $customer->country ?? '',
            number_format($customer->credit_limit, 2, ',', ' ') . ' FCFA',
            $customer->is_active ? 'Actif' : 'Inactif',
            $customer->sales_count ?? 0,
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
