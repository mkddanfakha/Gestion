<?php

namespace Database\Seeders;

use App\Auth\RolePresets;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CustomerIdentityService;
use App\Services\ProductStockInitializationService;
use App\Services\SalePaymentService;
use App\Services\SaleStockService;
use Database\Seeders\Support\DemoProductImageAttacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Jeu de données de démonstration LOCAL pour NIANE / MKD-Pro.
 *
 * Exécution recommandée : php artisan demo:seed-local
 * Interdit en production.
 */
class DemoLocalSeeder extends Seeder
{
    public const MARKER = '[DEMO-LOCAL]';

    public function run(bool $imagesOnly = false, bool $forceImages = false): void
    {
        if (App::environment('production')) {
            throw new RuntimeException('DemoLocalSeeder refusé en production.');
        }

        if ($imagesOnly) {
            $this->seedProductImages($forceImages);

            return;
        }

        $this->call(PermissionSeeder::class);

        DB::transaction(function () {
            $company = $this->seedCompany();
            $users = $this->seedUsers();
            $categories = $this->seedCategories();
            $products = $this->seedProducts($categories, $users['admin']);
            $this->seedProductImages();
            $customers = $this->seedCustomers();
            $suppliers = $this->seedSuppliers();
            $this->seedQuotes($users, $customers, $products);
            $this->seedSales($users, $customers, $products);
            $this->seedPurchaseFlow($users['admin'], $suppliers, $products);
            $this->seedExpenses($users, $suppliers);

            unset($company);
        });

        $imageStats = $this->productImageStats();

        $this->command?->info('Résumé démo:');
        $this->command?->table(
            ['Entité', 'Total'],
            [
                ['users', User::query()->count()],
                ['products', Product::query()->count()],
                ['products avec image', $imageStats['with_image']],
                ['customers', Customer::query()->count()],
                ['suppliers', Supplier::query()->count()],
                ['sales (factures)', Sale::query()->count()],
                ['quotes', Quote::query()->count()],
                ['purchase_orders', PurchaseOrder::query()->count()],
                ['delivery_notes', DeliveryNote::query()->count()],
                ['expenses', Expense::query()->count()],
            ],
        );
    }

    private function seedProductImages(bool $force = false): void
    {
        $result = (new DemoProductImageAttacher)->attachToDemoProducts($this->command, $force);

        $this->command?->info(sprintf(
            'Images produits : %d attachée(s), %d ignorée(s), %d sans image.',
            $result['attached'],
            $result['skipped'],
            $result['without_image'],
        ));
    }

    /**
     * @return array{total: int, with_image: int, without_image: int}
     */
    private function productImageStats(): array
    {
        $demoProducts = Product::query()
            ->where('description', 'like', '%'.self::MARKER.'%')
            ->get();

        $withImage = $demoProducts->filter(fn (Product $product) => $product->getThumbImageUrl() !== null)->count();

        return [
            'total' => $demoProducts->count(),
            'with_image' => $withImage,
            'without_image' => $demoProducts->count() - $withImage,
        ];
    }

    private function seedCompany(): Company
    {
        $company = Company::query()->first();

        $payload = [
            'name' => 'MKD-Pro Démo',
            'tagline' => 'Commerce de proximité — Dakar',
            'address' => 'Avenue Cheikh Anta Diop, Plateau, Dakar, Sénégal',
            'phone1' => '+221 33 821 00 00',
            'phone2' => '+221 77 100 20 30',
            'phone3' => null,
            'email' => 'contact@demo.mkd-pro.local',
            'website' => 'https://www.mkd-pro.com',
            'rc_number' => 'SN-DKR-2024-B-00123',
            'ncc_number' => 'SN1234567A',
            'print_signature_on_invoice' => true,
            'print_stamp_on_invoice' => true,
            'print_signature_on_quote' => true,
            'print_stamp_on_quote' => true,
            'print_signature_on_purchase_order' => true,
            'print_stamp_on_purchase_order' => true,
            'print_signature_on_delivery_note' => true,
            'print_stamp_on_delivery_note' => true,
        ];

        if ($company) {
            $company->update($payload);

            return $company->fresh();
        }

        return Company::query()->create($payload);
    }

    /**
     * @return array{admin: User, gestionnaire: User, vendeur: User}
     */
    private function seedUsers(): array
    {
        $definitions = [
            'admin' => [
                'name' => 'Admin Démo',
                'email' => 'admin@test.mkd-pro.local',
                'password' => 'Test1234!Admin',
                'role' => User::ROLE_ADMIN,
            ],
            'gestionnaire' => [
                'name' => 'Gestionnaire Démo',
                'email' => 'gestionnaire@test.mkd-pro.local',
                'password' => 'Test1234!Gestionnaire',
                'role' => User::ROLE_GESTIONNAIRE,
            ],
            'vendeur' => [
                'name' => 'Vendeur Démo',
                'email' => 'vendeur@test.mkd-pro.local',
                'password' => 'Test1234!Vendeur',
                'role' => User::ROLE_VENDEUR,
            ],
        ];

        $users = [];

        foreach ($definitions as $key => $def) {
            $user = User::query()->updateOrCreate(
                ['email' => $def['email']],
                [
                    'name' => $def['name'],
                    'password' => Hash::make($def['password']),
                    'role' => $def['role'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            if (RolePresets::usesBypass($def['role'])) {
                $user->permissions()->sync([]);
            } else {
                $user->permissions()->sync(RolePresets::permissionIds($def['role']));
            }

            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $defs = [
            'Alimentation' => ['description' => 'Denrées alimentaires de base', 'color' => '#10B981'],
            'Boissons' => ['description' => 'Eaux, jus et boissons gazeuses', 'color' => '#3B82F6'],
            'Hygiène' => ['description' => 'Produits d\'hygiène corporelle et ménagère', 'color' => '#8B5CF6'],
            'Épicerie' => ['description' => 'Épicerie fine et condiments', 'color' => '#F59E0B'],
            'Divers' => ['description' => 'Articles divers du quotidien', 'color' => '#64748B'],
        ];

        $out = [];

        foreach ($defs as $name => $meta) {
            $out[$name] = Category::query()->firstOrCreate(
                ['name' => $name],
                $meta,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, Category>  $categories
     * @return array<string, Product>
     */
    private function seedProducts(array $categories, User $admin): array
    {
        $stockInit = app(ProductStockInitializationService::class);

        // scenario: normal | low | out | expired | expiring
        $defs = [
            ['name' => 'Riz brisure 25 kg', 'cat' => 'Alimentation', 'price' => 14500, 'cost' => 12000, 'unit' => 'sac', 'min' => 5, 'qty' => 40, 'scenario' => 'normal', 'exp' => '+8 months'],
            ['name' => 'Riz parfumé 5 kg', 'cat' => 'Alimentation', 'price' => 4500, 'cost' => 3600, 'unit' => 'sac', 'min' => 10, 'qty' => 55, 'scenario' => 'normal', 'exp' => '+10 months'],
            ['name' => 'Huile végétale 5 L', 'cat' => 'Alimentation', 'price' => 6500, 'cost' => 5200, 'unit' => 'bidon', 'min' => 8, 'qty' => 30, 'scenario' => 'normal', 'exp' => '+6 months'],
            ['name' => 'Huile végétale 1 L', 'cat' => 'Alimentation', 'price' => 1500, 'cost' => 1100, 'unit' => 'bouteille', 'min' => 15, 'qty' => 80, 'scenario' => 'normal', 'exp' => '+6 months'],
            ['name' => 'Sucre en morceaux 1 kg', 'cat' => 'Alimentation', 'price' => 900, 'cost' => 700, 'unit' => 'paquet', 'min' => 20, 'qty' => 100, 'scenario' => 'normal', 'exp' => '+18 months'],
            ['name' => 'Lait en poudre 400 g', 'cat' => 'Alimentation', 'price' => 3200, 'cost' => 2500, 'unit' => 'boîte', 'min' => 12, 'qty' => 45, 'scenario' => 'normal', 'exp' => '+12 months'],
            ['name' => 'Café torréfié 250 g', 'cat' => 'Épicerie', 'price' => 2800, 'cost' => 2000, 'unit' => 'paquet', 'min' => 10, 'qty' => 35, 'scenario' => 'normal', 'exp' => '+9 months'],
            ['name' => 'Thé Lipton 25 sachets', 'cat' => 'Épicerie', 'price' => 1200, 'cost' => 850, 'unit' => 'boîte', 'min' => 15, 'qty' => 60, 'scenario' => 'normal', 'exp' => '+14 months'],
            ['name' => 'Eau minérale 1,5 L', 'cat' => 'Boissons', 'price' => 500, 'cost' => 300, 'unit' => 'bouteille', 'min' => 40, 'qty' => 200, 'scenario' => 'normal', 'exp' => '+24 months'],
            ['name' => 'Jus de bissap 1 L', 'cat' => 'Boissons', 'price' => 1000, 'cost' => 700, 'unit' => 'bouteille', 'min' => 20, 'qty' => 70, 'scenario' => 'normal', 'exp' => '+4 months'],
            ['name' => 'Boisson gazeuse 1,5 L', 'cat' => 'Boissons', 'price' => 900, 'cost' => 650, 'unit' => 'bouteille', 'min' => 24, 'qty' => 90, 'scenario' => 'normal', 'exp' => '+10 months'],
            ['name' => 'Biscuit fourré 150 g', 'cat' => 'Épicerie', 'price' => 700, 'cost' => 450, 'unit' => 'paquet', 'min' => 25, 'qty' => 110, 'scenario' => 'normal', 'exp' => '+8 months'],
            ['name' => 'Savon de Marseille 200 g', 'cat' => 'Hygiène', 'price' => 450, 'cost' => 280, 'unit' => 'pièce', 'min' => 30, 'qty' => 150, 'scenario' => 'normal', null],
            ['name' => 'Lessive en poudre 1 kg', 'cat' => 'Hygiène', 'price' => 2200, 'cost' => 1600, 'unit' => 'paquet', 'min' => 12, 'qty' => 40, 'scenario' => 'normal', null],
            ['name' => 'Dentifrice 75 ml', 'cat' => 'Hygiène', 'price' => 1100, 'cost' => 750, 'unit' => 'tube', 'min' => 15, 'qty' => 50, 'scenario' => 'normal', null],
            ['name' => 'Papier hygiénique x12', 'cat' => 'Hygiène', 'price' => 3500, 'cost' => 2600, 'unit' => 'paquet', 'min' => 10, 'qty' => 35, 'scenario' => 'normal', null],
            ['name' => 'Tomate concentrée 70 g', 'cat' => 'Épicerie', 'price' => 250, 'cost' => 150, 'unit' => 'boîte', 'min' => 40, 'qty' => 180, 'scenario' => 'normal', 'exp' => '+20 months'],
            ['name' => 'Spaghetti 500 g', 'cat' => 'Alimentation', 'price' => 600, 'cost' => 400, 'unit' => 'paquet', 'min' => 20, 'qty' => 95, 'scenario' => 'normal', 'exp' => '+16 months'],
            // stock faible
            ['name' => 'Sardines à l\'huile 125 g', 'cat' => 'Alimentation', 'price' => 650, 'cost' => 420, 'unit' => 'boîte', 'min' => 20, 'qty' => 8, 'scenario' => 'low', 'exp' => '+7 months'],
            ['name' => 'Mayonnaise 340 g', 'cat' => 'Épicerie', 'price' => 1800, 'cost' => 1300, 'unit' => 'bocal', 'min' => 10, 'qty' => 6, 'scenario' => 'low', 'exp' => '+5 months'],
            // rupture
            ['name' => 'Farine de blé 1 kg', 'cat' => 'Alimentation', 'price' => 750, 'cost' => 500, 'unit' => 'paquet', 'min' => 15, 'qty' => 0, 'scenario' => 'out', 'exp' => '+12 months'],
            ['name' => 'Savon liquide 500 ml', 'cat' => 'Hygiène', 'price' => 1600, 'cost' => 1100, 'unit' => 'flacon', 'min' => 8, 'qty' => 0, 'scenario' => 'out', null],
            // péremption
            ['name' => 'Yaourt nature 4x125 g', 'cat' => 'Alimentation', 'price' => 1400, 'cost' => 1000, 'unit' => 'pack', 'min' => 10, 'qty' => 18, 'scenario' => 'expiring', 'exp' => '+5 days', 'alert' => [7, 'days']],
            ['name' => 'Jus de gingembre 1 L', 'cat' => 'Boissons', 'price' => 1200, 'cost' => 800, 'unit' => 'bouteille', 'min' => 10, 'qty' => 12, 'scenario' => 'expired', 'exp' => '-10 days', 'alert' => [14, 'days']],
            ['name' => 'Allumettes sécurité', 'cat' => 'Divers', 'price' => 200, 'cost' => 100, 'unit' => 'boîte', 'min' => 20, 'qty' => 75, 'scenario' => 'normal', null],
            ['name' => 'Sacs plastique 50 pcs', 'cat' => 'Divers', 'price' => 500, 'cost' => 250, 'unit' => 'paquet', 'min' => 15, 'qty' => 60, 'scenario' => 'normal', null],
        ];

        $products = [];
        $skuCounters = [];
        $productIndex = 0;

        foreach ($defs as $def) {
            $productIndex++;
            $category = $categories[$def['cat']];
            $prefix = match ($def['cat']) {
                'Alimentation' => 'AL',
                'Boissons' => 'BO',
                'Hygiène' => 'HY',
                'Épicerie' => 'EP',
                default => 'DI',
            };

            $skuCounters[$prefix] = ($skuCounters[$prefix] ?? 0) + 1;
            $sku = $prefix.str_pad((string) $skuCounters[$prefix], 4, '0', STR_PAD_LEFT);

            $expiration = null;
            if (! empty($def['exp'])) {
                $expiration = now()->modify($def['exp'])->toDateString();
            }

            $alertValue = $def['alert'][0] ?? ($expiration ? 30 : null);
            $alertUnit = $def['alert'][1] ?? ($expiration ? 'days' : null);

            $product = Product::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => $def['name'],
                    'description' => self::MARKER.' '.$def['name'].' (scénario: '.$def['scenario'].')',
                    'barcode' => '200'.str_pad((string) $productIndex, 10, '0', STR_PAD_LEFT),
                    'price' => $def['price'],
                    'cost_price' => $def['cost'],
                    'stock_quantity' => 0,
                    'min_stock_level' => $def['min'],
                    'unit' => $def['unit'],
                    'location' => 'Rayon '.$prefix,
                    'is_active' => true,
                    'category_id' => $category->id,
                    'expiration_date' => $expiration,
                    'alert_threshold_value' => $alertValue,
                    'alert_threshold_unit' => $alertUnit,
                ],
            );

            if (! $product->productStocks()->exists()) {
                $stockInit->initializeMainStock($product, (int) $def['qty'], $admin);
            } else {
                // Conserver le stock existant si déjà initialisé (idempotence).
                $product->refresh();
            }

            $products[$sku] = $product->fresh();
        }

        return $products;
    }

    /**
     * @return list<Customer>
     */
    private function seedCustomers(): array
    {
        $defs = [
            [
                'name' => 'Aminata Diop',
                'email' => 'aminata.diop@demo.local',
                'phone' => '+221 77 111 22 33',
                'nationality' => 'SN',
                'identity_document_type' => CustomerIdentityService::TYPE_NATIONAL_ID,
                'identity_document_number' => '1234567890123',
                'address' => 'Sicap Liberté 6',
                'city' => 'Dakar',
                'postal_code' => '12500',
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Particulier sénégalais',
            ],
            [
                'name' => 'Boutique Ndar SARL',
                'email' => 'contact@boutiquendar.demo',
                'phone' => '+221 33 961 10 20',
                'nationality' => 'SN',
                'identity_document_type' => CustomerIdentityService::TYPE_OTHER,
                'identity_document_number' => 'RC-SNDKR-7788',
                'address' => 'Rue de la Gare',
                'city' => 'Saint-Louis',
                'postal_code' => '32000',
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Client entreprise',
            ],
            [
                'name' => 'Moussa Ndiaye',
                'email' => null,
                'phone' => '+221 78 555 66 77',
                'nationality' => 'SN',
                'identity_document_type' => null,
                'identity_document_number' => null,
                'address' => 'Parcelles Assainies U22',
                'city' => 'Dakar',
                'postal_code' => null,
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Avec téléphone, sans email',
            ],
            [
                'name' => 'Fatou Ba',
                'email' => 'fatou.ba@demo.local',
                'phone' => null,
                'nationality' => 'SN',
                'identity_document_type' => CustomerIdentityService::TYPE_NATIONAL_ID,
                'identity_document_number' => '9876543210987',
                'address' => null,
                'city' => 'Thiès',
                'postal_code' => null,
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Sans téléphone',
            ],
            [
                'name' => 'Ibrahima Sow',
                'email' => 'ibrahima.sow@demo.local',
                'phone' => '+221 76 222 33 44',
                'nationality' => 'SN',
                'identity_document_type' => CustomerIdentityService::TYPE_PASSPORT,
                'identity_document_number' => 'A12345678',
                'address' => 'Almadies, Route de Ngor',
                'city' => 'Dakar',
                'postal_code' => '12000',
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Avec adresse complète',
            ],
            [
                'name' => 'Jean-Pierre Kouassi',
                'email' => 'jp.kouassi@demo.local',
                'phone' => '+225 07 01 02 03 04',
                'nationality' => 'CI',
                'identity_document_type' => CustomerIdentityService::TYPE_PASSPORT,
                'identity_document_number' => 'CIABJ445566',
                'address' => 'Cocody Riviera',
                'city' => 'Abidjan',
                'postal_code' => null,
                'country' => 'Côte d\'Ivoire',
                'notes' => self::MARKER.' Client étranger (CI)',
            ],
            [
                'name' => 'Client passage',
                'email' => null,
                'phone' => null,
                'nationality' => null,
                'identity_document_type' => null,
                'identity_document_number' => null,
                'address' => null,
                'city' => null,
                'postal_code' => null,
                'country' => null,
                'notes' => self::MARKER.' Minimal',
            ],
            [
                'name' => 'Awa Fall',
                'email' => 'awa.fall@demo.local',
                'phone' => '+221 70 444 55 66',
                'nationality' => 'SN',
                'identity_document_type' => CustomerIdentityService::TYPE_RESIDENCE_PERMIT,
                'identity_document_number' => 'CS-DK-2024-8899',
                'address' => 'Guédiawaye Cité Millionnaire',
                'city' => 'Guédiawaye',
                'postal_code' => null,
                'country' => 'Sénégal',
                'notes' => self::MARKER.' Carte de séjour',
            ],
        ];

        $customers = [];

        foreach ($defs as $def) {
            $customer = null;

            if (! empty($def['email'])) {
                $customer = Customer::query()->where('email', $def['email'])->first();
            }

            if (! $customer) {
                $customer = Customer::query()
                    ->where('name', $def['name'])
                    ->where('notes', 'like', '%'.self::MARKER.'%')
                    ->first();
            }

            if ($customer) {
                $customer->update($def + ['is_active' => true]);
            } else {
                $customer = Customer::query()->create($def + ['is_active' => true]);
            }

            $customers[] = $customer->fresh();
        }

        return $customers;
    }

    /**
     * @return list<Supplier>
     */
    private function seedSuppliers(): array
    {
        $defs = [
            [
                'name' => 'SODIDA Grossiste',
                'contact_person' => 'Ousmane Sarr',
                'email' => 'commandes@sodida.demo',
                'phone' => '+221 33 832 10 10',
                'mobile' => '+221 77 200 30 40',
                'address' => 'Zone industrielle SODIDA',
                'city' => 'Dakar',
                'country' => 'Sénégal',
                'tax_id' => 'SN-TAX-1001',
                'notes' => self::MARKER.' Alimentation & boissons',
                'status' => 'active',
            ],
            [
                'name' => 'Hygiène Plus SN',
                'contact_person' => 'Khady Gueye',
                'email' => 'vente@hygieneplus.demo',
                'phone' => '+221 33 855 22 11',
                'mobile' => '+221 78 300 40 50',
                'address' => 'Marché Castor',
                'city' => 'Dakar',
                'country' => 'Sénégal',
                'tax_id' => 'SN-TAX-1002',
                'notes' => self::MARKER.' Hygiène',
                'status' => 'active',
            ],
            [
                'name' => 'Import Sahel Trading',
                'contact_person' => 'Amadou Ba',
                'email' => 'info@saheltrading.demo',
                'phone' => '+221 33 889 00 11',
                'mobile' => null,
                'address' => 'Port autonome de Dakar',
                'city' => 'Dakar',
                'country' => 'Sénégal',
                'tax_id' => 'SN-TAX-1003',
                'notes' => self::MARKER.' Import',
                'status' => 'active',
            ],
            [
                'name' => 'Fournisseur Inactif Demo',
                'contact_person' => null,
                'email' => 'inactif@demo.local',
                'phone' => '+221 33 800 00 00',
                'mobile' => null,
                'address' => null,
                'city' => 'Kaolack',
                'country' => 'Sénégal',
                'tax_id' => null,
                'notes' => self::MARKER.' Inactif',
                'status' => 'inactive',
            ],
        ];

        $suppliers = [];

        foreach ($defs as $def) {
            $suppliers[] = Supplier::query()->updateOrCreate(
                ['name' => $def['name']],
                $def,
            );
        }

        return $suppliers;
    }

    /**
     * @param  array{admin: User, gestionnaire: User, vendeur: User}  $users
     * @param  list<Customer>  $customers
     * @param  array<string, Product>  $products
     */
    private function seedQuotes(array $users, array $customers, array $products): void
    {
        if (Quote::query()->where('notes', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->command?->warn('Devis démo déjà présents — skip.');

            return;
        }

        $list = array_values($products);
        $statuses = [
            ['status' => 'draft', 'valid_until' => now()->addDays(15)],
            ['status' => 'sent', 'valid_until' => now()->addDays(10)],
            ['status' => 'accepted', 'valid_until' => now()->addDays(20)],
            ['status' => 'rejected', 'valid_until' => now()->addDays(5)],
            ['status' => 'expired', 'valid_until' => now()->subDays(3)],
        ];

        foreach ($statuses as $i => $meta) {
            $items = [
                $list[$i % count($list)],
                $list[($i + 3) % count($list)],
            ];

            $subtotal = 0;
            $lineData = [];

            foreach ($items as $product) {
                $qty = 2 + $i;
                $lineTotal = (float) $product->price * $qty;
                $subtotal += $lineTotal;
                $lineData[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'total_price' => $lineTotal,
                ];
            }

            $discount = $i === 2 ? 500 : 0;
            $tax = 0;
            $total = $subtotal + $tax - $discount;

            $quote = Quote::query()->create([
                'quote_number' => Quote::generateQuoteNumber(),
                'customer_id' => $customers[$i % count($customers)]->id,
                'user_id' => $users['vendeur']->id,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'status' => $meta['status'],
                'valid_until' => $meta['valid_until']->toDateString(),
                'notes' => self::MARKER.' Devis statut '.$meta['status'],
                'quote_date' => now()->subDays(5 - $i),
            ]);

            foreach ($lineData as $line) {
                QuoteItem::query()->create([
                    'quote_id' => $quote->id,
                    'product_id' => $line['product']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'discount_amount' => 0,
                ]);
            }
        }
    }

    /**
     * @param  array{admin: User, gestionnaire: User, vendeur: User}  $users
     * @param  list<Customer>  $customers
     * @param  array<string, Product>  $products
     */
    private function seedSales(array $users, array $customers, array $products): void
    {
        if (Sale::query()->where('notes', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->command?->warn('Ventes démo déjà présentes — skip.');

            return;
        }

        $saleStock = app(SaleStockService::class);

        // Produits avec stock confortable pour les ventes
        $sellable = collect($products)
            ->filter(fn (Product $p) => $p->stock_quantity >= 10)
            ->values();

        if ($sellable->count() < 3) {
            $this->command?->warn('Pas assez de stock pour créer des ventes démo.');

            return;
        }

        $scenarios = [
            [
                'label' => 'simple cash',
                'customer' => $customers[0],
                'user' => $users['vendeur'],
                'items' => [[$sellable[0], 1]],
                'payment_method' => 'cash',
                'down' => 'full',
                'days_ago' => 1,
                'due' => null,
            ],
            [
                'label' => 'multi articles wave',
                'customer' => $customers[1],
                'user' => $users['vendeur'],
                'items' => [[$sellable[1], 2], [$sellable[2], 3]],
                'payment_method' => 'wave',
                'down' => 'full',
                'days_ago' => 2,
                'due' => null,
            ],
            [
                'label' => 'importante orange_money',
                'customer' => $customers[4],
                'user' => $users['admin'],
                'items' => [[$sellable[0], 5], [$sellable[3] ?? $sellable[1], 4], [$sellable[4] ?? $sellable[2], 6]],
                'payment_method' => 'orange_money',
                'down' => 'full',
                'days_ago' => 3,
                'due' => null,
            ],
            [
                'label' => 'sans client cash',
                'customer' => null,
                'user' => $users['vendeur'],
                'items' => [[$sellable[5] ?? $sellable[0], 2]],
                'payment_method' => 'cash',
                'down' => 'full',
                'days_ago' => 0,
                'due' => null,
            ],
            [
                'label' => 'partielle bank_transfer',
                'customer' => $customers[1],
                'user' => $users['admin'],
                'items' => [[$sellable[6] ?? $sellable[1], 3], [$sellable[7] ?? $sellable[2], 2]],
                'payment_method' => 'bank_transfer',
                'down' => 'half',
                'days_ago' => 5,
                'due' => now()->addDays(10),
            ],
            [
                'label' => 'impayée échéance aujourd\'hui',
                'customer' => $customers[5],
                'user' => $users['vendeur'],
                'items' => [[$sellable[8] ?? $sellable[0], 1]],
                'payment_method' => null,
                'down' => 'none',
                'days_ago' => 7,
                'due' => now(),
            ],
            [
                'label' => 'check récente',
                'customer' => $customers[2],
                'user' => $users['vendeur'],
                'items' => [[$sellable[9] ?? $sellable[1], 2], [$sellable[10] ?? $sellable[2], 1]],
                'payment_method' => 'check',
                'down' => 'full',
                'days_ago' => 0,
                'due' => null,
            ],
            [
                'label' => 'card historique',
                'customer' => $customers[3],
                'user' => $users['admin'],
                'items' => [[$sellable[11] ?? $sellable[0], 4]],
                'payment_method' => 'card',
                'down' => 'full',
                'days_ago' => 14,
                'due' => null,
            ],
        ];

        foreach ($scenarios as $scenario) {
            $lineData = [];
            $productQuantities = [];
            $subtotal = 0.0;

            foreach ($scenario['items'] as [$product, $qty]) {
                /** @var Product $product */
                $product = $product->fresh();
                $available = $saleStock->getAvailableStock($product->id);

                if ($available < $qty) {
                    $this->command?->warn("Stock insuffisant pour {$product->name} (besoin {$qty}, dispo {$available}) — skip ligne.");

                    continue;
                }

                $lineTotal = (float) $product->price * $qty;
                $subtotal += $lineTotal;
                $lineData[] = [
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'total_price' => $lineTotal,
                ];
                $productQuantities[$product->id] = ($productQuantities[$product->id] ?? 0) + $qty;
            }

            if ($lineData === []) {
                continue;
            }

            $tax = 0.0;
            $discount = 0.0;
            $total = $subtotal + $tax - $discount;

            $downPayment = match ($scenario['down']) {
                'full' => $total,
                'half' => round($total / 2, 2),
                default => 0.0,
            };

            $paymentState = SalePaymentService::calculatePaymentState($total, $downPayment);
            $paymentMethod = SalePaymentService::resolvePaymentMethod(
                $scenario['payment_method'],
                $paymentState['down_payment_amount'],
            );

            $sale = Sale::query()->create([
                'sale_number' => Sale::generateSaleNumber(),
                'customer_id' => $scenario['customer']?->id,
                'user_id' => $scenario['user']->id,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'down_payment_amount' => $paymentState['down_payment_amount'],
                'remaining_amount' => $paymentState['remaining_amount'],
                'payment_status' => $paymentState['payment_status'],
                'total_amount' => $total,
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'notes' => self::MARKER.' '.$scenario['label'],
                'sale_date' => now()->subDays($scenario['days_ago']),
                'due_date' => $scenario['due']?->toDateString(),
            ]);

            foreach ($lineData as $line) {
                SaleItem::query()->create(['sale_id' => $sale->id] + $line);
            }

            $saleStock->applySaleCreation($sale, $productQuantities);
        }
    }

    /**
     * @param  list<Supplier>  $suppliers
     * @param  array<string, Product>  $products
     */
    private function seedPurchaseFlow(User $admin, array $suppliers, array $products): void
    {
        if (PurchaseOrder::query()->where('notes', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->command?->warn('BC/BL démo déjà présents — skip.');

            return;
        }

        $activeSuppliers = array_values(array_filter(
            $suppliers,
            fn (Supplier $s) => $s->status === 'active',
        ));

        $productList = array_values($products);

        $poDefs = [
            ['status' => 'draft', 'supplier' => $activeSuppliers[0], 'items' => 2],
            ['status' => 'sent', 'supplier' => $activeSuppliers[1], 'items' => 2],
            ['status' => 'confirmed', 'supplier' => $activeSuppliers[0], 'items' => 3, 'pending_bl' => true],
            ['status' => 'confirmed', 'supplier' => $activeSuppliers[1], 'items' => 2, 'partial_bl' => true],
            ['status' => 'confirmed', 'supplier' => $activeSuppliers[2], 'items' => 2, 'full_bl' => true],
        ];

        foreach ($poDefs as $i => $def) {
            $lines = [];
            $subtotal = 0.0;

            for ($j = 0; $j < $def['items']; $j++) {
                $product = $productList[($i * 2 + $j) % count($productList)];
                $qty = 10 + $j * 5;
                $unit = (float) ($product->cost_price ?: $product->price);
                $lineTotal = $unit * $qty;
                $subtotal += $lineTotal;
                $lines[] = [
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'total_price' => $lineTotal,
                ];
            }

            $po = PurchaseOrder::query()->create([
                'po_number' => PurchaseOrder::generatePONumber(),
                'supplier_id' => $def['supplier']->id,
                'order_date' => now()->subDays(10 - $i)->toDateString(),
                'expected_delivery_date' => now()->addDays(3 + $i)->toDateString(),
                'status' => $def['status'],
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $subtotal,
                'notes' => self::MARKER.' BC '.$def['status'],
                'user_id' => $admin->id,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id] + $line);
            }

            if (! empty($def['partial_bl'])) {
                $this->createDeliveryNote($po, $admin, $lines, partial: true, validate: true);
            }

            if (! empty($def['full_bl'])) {
                $this->createDeliveryNote($po, $admin, $lines, partial: false, validate: true);
            }

            if (! empty($def['pending_bl'])) {
                $this->createDeliveryNote($po, $admin, $lines, partial: false, validate: false);
            }
        }
    }

    /**
     * @param  list<array{product_id: int, quantity: int, unit_price: float|string, total_price: float}>  $poLines
     */
    private function createDeliveryNote(
        PurchaseOrder $po,
        User $admin,
        array $poLines,
        bool $partial,
        bool $validate,
    ): void {
        $dnLines = [];
        $subtotal = 0.0;

        foreach ($poLines as $idx => $line) {
            $qty = $partial && $idx === 0
                ? max(1, (int) floor($line['quantity'] / 2))
                : ($partial && $idx > 0 ? 0 : $line['quantity']);

            if ($qty <= 0) {
                continue;
            }

            $lineTotal = (float) $line['unit_price'] * $qty;
            $subtotal += $lineTotal;
            $dnLines[] = [
                'product_id' => $line['product_id'],
                'quantity' => $qty,
                'unit_price' => $line['unit_price'],
                'total_price' => $lineTotal,
            ];
        }

        if ($dnLines === []) {
            return;
        }

        $dn = DeliveryNote::query()->create([
            'delivery_number' => DeliveryNote::generateDeliveryNumber(),
            'purchase_order_id' => $po->id,
            'supplier_id' => $po->supplier_id,
            'delivery_date' => now()->subDays(1)->toDateString(),
            'status' => 'pending',
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $subtotal,
            'notes' => self::MARKER.($validate ? ' BL validé' : ' BL pending'),
            'user_id' => $admin->id,
        ]);

        foreach ($dnLines as $line) {
            DeliveryNoteItem::query()->create(['delivery_note_id' => $dn->id] + $line);
        }

        if ($validate) {
            $dn->fresh(['items'])->validate();
        }
    }

    /**
     * @param  array{admin: User, gestionnaire: User, vendeur: User}  $users
     * @param  list<Supplier>  $suppliers
     */
    private function seedExpenses(array $users, array $suppliers): void
    {
        if (Expense::query()->where('notes', 'like', '%'.self::MARKER.'%')->exists()) {
            $this->command?->warn('Dépenses démo déjà présentes — skip.');

            return;
        }

        $defs = [
            [
                'title' => 'Carburant livraison',
                'amount' => 25000,
                'category' => 'transport',
                'payment_method' => 'cash',
                'vendor' => 'Station Total Almadies',
                'user' => $users['gestionnaire'],
                'supplier_id' => null,
            ],
            [
                'title' => 'Fournitures bureau',
                'amount' => 18500,
                'category' => 'fournitures',
                'payment_method' => 'wave',
                'vendor' => 'Papeterie Plateau',
                'user' => $users['gestionnaire'],
                'supplier_id' => null,
            ],
            [
                'title' => 'Achat stock hygiène',
                'amount' => 75000,
                'category' => 'fournitures',
                'payment_method' => 'bank_transfer',
                'vendor' => $suppliers[1]->name,
                'user' => $users['admin'],
                'supplier_id' => $suppliers[1]->id,
            ],
        ];

        foreach ($defs as $i => $def) {
            Expense::query()->create([
                'expense_number' => Expense::generateExpenseNumber(),
                'title' => $def['title'],
                'description' => self::MARKER.' '.$def['title'],
                'amount' => $def['amount'],
                'category' => $def['category'],
                'payment_method' => $def['payment_method'],
                'expense_date' => now()->subDays($i + 1)->toDateString(),
                'receipt_number' => 'REC-DEMO-'.($i + 1),
                'vendor' => $def['vendor'],
                'supplier_id' => $def['supplier_id'],
                'notes' => self::MARKER,
                'user_id' => $def['user']->id,
            ]);
        }
    }
}
