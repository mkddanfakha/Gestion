<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerExportService
{
    public function __construct(
        private CustomerIdentityService $identityService,
    ) {}

    public function buildQuery(?string $search = null): Builder
    {
        $query = Customer::query()->withCount('sales');

        if ($search !== null && trim($search) !== '') {
            $this->identityService->applySearchFilter($query, trim($search));
        }

        return $query->orderBy('name');
    }

    /**
     * @return Collection<int, Customer>
     */
    public function getCustomers(?string $search = null): Collection
    {
        return $this->buildQuery($search)->get();
    }

    public function nationalityLabel(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            return '';
        }

        return Countries::name(strtoupper(trim($code))) ?? strtoupper(trim($code));
    }

    public function identityTypeLabel(?string $type): string
    {
        return $this->identityService->typeLabel($type) ?? '';
    }

    public function formatDate(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('d/m/Y');
        }

        return '';
    }

    public function formatDateTime(mixed $date): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('d/m/Y H:i');
        }

        return '';
    }

    public function displayValue(?string $value, string $empty = '—'): string
    {
        if ($value === null || trim($value) === '') {
            return $empty;
        }

        return trim($value);
    }

    public function formatStatus(bool $isActive): string
    {
        return $isActive ? 'Actif' : 'Inactif';
    }

    /**
     * @return list<string>
     */
    public function excelHeadings(): array
    {
        return [
            'ID',
            'Nom',
            'Email',
            'Téléphone',
            'Adresse',
            'Ville',
            'Code postal',
            'Pays de résidence',
            'Nationalité',
            'Type de pièce',
            'Numéro de pièce',
            'Date de naissance',
            'Notes',
            'Statut',
            'Nombre de ventes',
            'Date de création',
        ];
    }

    /**
     * @return list<int|string|null>
     */
    public function mapCustomerForExcel(Customer $customer): array
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
            $this->nationalityLabel($customer->nationality),
            $this->identityTypeLabel($customer->identity_document_type),
            $customer->identity_document_number ?? '',
            $this->formatDate($customer->birthday),
            $customer->notes ?? '',
            $this->formatStatus((bool) $customer->is_active),
            $customer->sales_count ?? 0,
            $this->formatDateTime($customer->created_at),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public function mapCustomerForPdfList(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $this->displayValue($customer->email),
            'phone' => $this->displayValue($customer->phone),
            'nationality' => $this->displayValue($this->nationalityLabel($customer->nationality)),
            'country' => $this->displayValue($customer->country),
            'identity_type' => $this->displayValue($this->identityTypeLabel($customer->identity_document_type)),
            'identity_number' => $this->displayValue($customer->identity_document_number),
            'status' => $this->formatStatus((bool) $customer->is_active),
            'sales_count' => $customer->sales_count ?? 0,
        ];
    }
}
