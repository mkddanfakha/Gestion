<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerIdentityService
{
    public const TYPE_NATIONAL_ID = 'national_id';

    public const TYPE_PASSPORT = 'passport';

    public const TYPE_OTHER = 'other';

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_NATIONAL_ID => 'Carte d\'identité',
        self::TYPE_PASSPORT => 'Passeport',
        self::TYPE_OTHER => 'Autre',
    ];

    /** @var list<string> */
    public const TYPE_VALUES = [
        self::TYPE_NATIONAL_ID,
        self::TYPE_PASSPORT,
        self::TYPE_OTHER,
    ];

    /**
     * Règle d'unicité : la combinaison (type + numéro normalisé) est unique.
     * Le même numéro peut exister avec un type différent (ex. CNI vs Passeport).
     */
    public function normalizeDocumentNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $normalized = Str::upper(trim($number));
        $normalized = preg_replace('/[\s\-]+/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Normalise un téléphone pour la détection de doublons.
     * Conserve les chiffres ; pour le Sénégal, compare sur les 9 derniers chiffres locaux.
     */
    public function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00221')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '221') && strlen($digits) > 9) {
            $digits = substr($digits, 3);
        }

        if (strlen($digits) > 9) {
            $digits = substr($digits, -9);
        }

        return $digits !== '' ? $digits : null;
    }

    public function typeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::TYPE_LABELS[$type] ?? $type;
    }

    public function typeShortLabel(?string $type): ?string
    {
        return match ($type) {
            self::TYPE_NATIONAL_ID => 'CNI',
            self::TYPE_PASSPORT => 'Passeport',
            self::TYPE_OTHER => 'Autre',
            default => $this->typeLabel($type),
        };
    }

    public function maskDocumentNumber(?string $number): ?string
    {
        if ($number === null || $number === '') {
            return null;
        }

        $normalized = $this->normalizeDocumentNumber($number) ?? trim($number);
        $length = strlen($normalized);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', max(0, $length - 4)) . substr($normalized, -4);
    }

    public function prepareAttributes(array $data): array
    {
        $type = isset($data['identity_document_type']) && $data['identity_document_type'] !== ''
            ? (string) $data['identity_document_type']
            : null;
        $number = isset($data['identity_document_number']) && $data['identity_document_number'] !== ''
            ? trim((string) $data['identity_document_number'])
            : null;

        if ($type === null && $number === null) {
            $data['identity_document_type'] = null;
            $data['identity_document_number'] = null;
            $data['identity_document_number_normalized'] = null;
        } else {
            $data['identity_document_number'] = $number;
            $data['identity_document_number_normalized'] = ($type && $number)
                ? $this->normalizeDocumentNumber($number)
                : null;
        }

        $data['phone_normalized'] = !empty($data['phone'])
            ? $this->normalizePhone((string) $data['phone'])
            : null;

        return $data;
    }

    public function findByIdentity(string $type, string $number, ?int $excludeId = null): ?Customer
    {
        $normalized = $this->normalizeDocumentNumber($number);

        if (!$type || !$normalized) {
            return null;
        }

        $query = Customer::query()
            ->where('identity_document_type', $type)
            ->where('identity_document_number_normalized', $normalized);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function findByPhone(string $phone, ?int $excludeId = null): ?Customer
    {
        $normalized = $this->normalizePhone($phone);

        if (!$normalized) {
            return null;
        }

        $query = Customer::query()->where('phone_normalized', $normalized);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function findByEmail(string $email, ?int $excludeId = null): ?Customer
    {
        $email = Str::lower(trim($email));

        if ($email === '') {
            return null;
        }

        $query = Customer::query()->whereRaw('LOWER(email) = ?', [$email]);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * @return Collection<int, Customer>
     */
    public function findSimilarByName(string $name, ?int $excludeId = null, int $limit = 5): Collection
    {
        $needle = Str::lower(trim($name));

        if (strlen($needle) < 2) {
            return collect();
        }

        $query = Customer::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $needle . '%'])
            ->orderBy('name')
            ->limit($limit);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }

    /**
     * Prépare les critères de détection depuis une requête tolérante (sans 422 sur saisie partielle).
     *
     * @return array<string, mixed>
     */
    public function parseDuplicateCheckCriteria(array $input): array
    {
        $criteria = [];

        $name = trim((string) ($input['name'] ?? ''));
        if (strlen($name) >= 2) {
            $criteria['name'] = $name;
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $criteria['email'] = $email;
        }

        $phone = trim((string) ($input['phone'] ?? ''));
        if ($this->isPlausiblePhone($phone)) {
            $criteria['phone'] = $phone;
        }

        $type = $input['identity_document_type'] ?? null;
        $number = trim((string) ($input['identity_document_number'] ?? ''));
        if (
            is_string($type)
            && in_array($type, self::TYPE_VALUES, true)
            && strlen($number) >= 3
        ) {
            $criteria['identity_document_type'] = $type;
            $criteria['identity_document_number'] = $number;
        }

        $excludeId = $input['exclude_id'] ?? $input['customer_id'] ?? null;
        if ($excludeId) {
            $criteria['exclude_id'] = (int) $excludeId;
        }

        return $criteria;
    }

    public function isPlausiblePhone(string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 8;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeDuplicates(array $data, ?int $excludeId = null): array
    {
        $type = $data['identity_document_type'] ?? null;
        $number = $data['identity_document_number'] ?? null;
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;
        $name = $data['name'] ?? null;

        $identityConflict = null;
        $identityAvailable = true;
        $matches = [];

        if ($type && $number) {
            $existing = $this->findByIdentity((string) $type, (string) $number, $excludeId);
            if ($existing) {
                $identityAvailable = false;
                $identityConflict = $this->formatCustomerMatch($existing, 'identity_document');
                $matches[] = $identityConflict;
            }
        }

        $phoneMatch = null;
        if ($phone) {
            $existingPhone = $this->findByPhone((string) $phone, $excludeId);
            if ($existingPhone) {
                $phoneMatch = $this->formatCustomerMatch($existingPhone, 'phone');
                $matches[] = $phoneMatch;
            }
        }

        $emailMatch = null;
        if ($email) {
            $existingEmail = $this->findByEmail((string) $email, $excludeId);
            if ($existingEmail) {
                $emailMatch = $this->formatCustomerMatch($existingEmail, 'email');
                $matches[] = $emailMatch;
            }
        }

        $similarNames = collect();
        if ($name && strlen(trim((string) $name)) >= 2) {
            $similarNames = $this->findSimilarByName((string) $name, $excludeId)
                ->map(fn (Customer $customer) => $this->formatCustomerMatch($customer, 'name'));
        }

        foreach ($similarNames as $similar) {
            $matches[] = $similar;
        }

        return [
            'identity_available' => $identityAvailable,
            'identity_conflict' => $identityConflict,
            'phone_match' => $phoneMatch,
            'email_match' => $emailMatch,
            'similar_names' => $similarNames->values()->all(),
            'has_duplicates' => !empty($matches),
            'matches' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepareValidatedAttributes(array $data, ?Customer $customer = null): array
    {
        $prepared = $this->prepareAttributes($data);

        $type = $prepared['identity_document_type'] ?? null;
        $number = $prepared['identity_document_number'] ?? null;

        if (($type && !$number) || ($number && !$type)) {
            throw ValidationException::withMessages([
                'identity_document_type' => ['Le type et le numéro de pièce doivent être renseignés ensemble.'],
                'identity_document_number' => ['Le type et le numéro de pièce doivent être renseignés ensemble.'],
            ]);
        }

        if ($type && $number) {
            $this->assertIdentityUnique($type, $number, $customer?->id);
        }

        return $prepared;
    }

    /**
     * @throws ValidationException
     */
    public function assertIdentityUnique(string $type, string $number, ?int $excludeId = null): void
    {
        $existing = $this->findByIdentity($type, $number, $excludeId);

        if (!$existing) {
            return;
        }

        throw ValidationException::withMessages([
            'identity_document_number' => ['Un client possède déjà cette pièce d\'identité.'],
        ]);
    }

    public function findIdentityConflict(string $type, string $number, ?int $excludeId = null): ?Customer
    {
        return $this->findByIdentity($type, $number, $excludeId);
    }

    public function formatCustomerMatch(?Customer $customer, ?string $matchType = null): ?array
    {
        if (!$customer) {
            return null;
        }

        $match = [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'identity_document_type' => $customer->identity_document_type,
            'identity_document_type_label' => $this->typeLabel($customer->identity_document_type),
            'identity_document_type_short' => $this->typeShortLabel($customer->identity_document_type),
            'identity_document_number_masked' => $this->maskDocumentNumber($customer->identity_document_number),
        ];

        if ($matchType) {
            $match['match_type'] = $matchType;
        }

        return $match;
    }

    public function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'customers_identity_document_unique')
            || str_contains($message, 'unique constraint failed: customers.identity_document_type');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPotentialDuplicateGroups(int $limit = 50): array
    {
        $groups = [];

        $identityGroups = Customer::query()
            ->select([
                'identity_document_type',
                'identity_document_number_normalized',
            ])
            ->whereNotNull('identity_document_type')
            ->whereNotNull('identity_document_number_normalized')
            ->groupBy('identity_document_type', 'identity_document_number_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->get();

        foreach ($identityGroups as $group) {
            $customers = Customer::query()
                ->where('identity_document_type', $group->identity_document_type)
                ->where('identity_document_number_normalized', $group->identity_document_number_normalized)
                ->orderBy('name')
                ->get();

            $groups[] = [
                'reason' => 'identity',
                'label' => 'Même pièce d\'identité',
                'customers' => $customers->map(fn (Customer $c) => $this->formatCustomerMatch($c))->all(),
            ];
        }

        $phoneGroups = Customer::query()
            ->select('phone_normalized')
            ->whereNotNull('phone_normalized')
            ->groupBy('phone_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->get();

        foreach ($phoneGroups as $group) {
            $customers = Customer::query()
                ->where('phone_normalized', $group->phone_normalized)
                ->orderBy('name')
                ->get();

            $groups[] = [
                'reason' => 'phone',
                'label' => 'Même téléphone',
                'customers' => $customers->map(fn (Customer $c) => $this->formatCustomerMatch($c))->all(),
            ];
        }

        $emailGroups = Customer::query()
            ->selectRaw('LOWER(email) as normalized_email')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('normalized_email')
            ->havingRaw('COUNT(*) > 1')
            ->limit($limit)
            ->get();

        foreach ($emailGroups as $group) {
            $customers = Customer::query()
                ->whereRaw('LOWER(email) = ?', [$group->normalized_email])
                ->orderBy('name')
                ->get();

            $groups[] = [
                'reason' => 'email',
                'label' => 'Même email',
                'customers' => $customers->map(fn (Customer $c) => $this->formatCustomerMatch($c))->all(),
            ];
        }

        return $groups;
    }

    public function applySearchFilter($query, string $search): void
    {
        $search = trim($search);
        $normalizedDocument = $this->normalizeDocumentNumber($search);
        $normalizedPhone = $this->normalizePhone($search);

        $query->where(function ($builder) use ($search, $normalizedDocument, $normalizedPhone) {
            $builder->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('identity_document_number', 'like', "%{$search}%");

            if ($normalizedDocument) {
                $builder->orWhere('identity_document_number_normalized', 'like', "%{$normalizedDocument}%");
            }

            if ($normalizedPhone) {
                $builder->orWhere('phone_normalized', 'like', "%{$normalizedPhone}%");
            }
        });
    }
}
