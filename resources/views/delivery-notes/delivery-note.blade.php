<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Bon de livraison {{ $deliveryNote->delivery_number }}</title>
    @include('partials.document-styles')
    <style>
        /* Styles spécifiques aux bons de livraison */
        /* Compatibilité avec les anciennes classes */
        .dn-document,
        .dn-meta {
            /* Utilise les styles de invoice-document et invoice-meta du partial */
        }
        
        /* Statuts spécifiques aux bons de livraison */
        .status-pending {
            border-color: #ffc107;
            color: #856404;
        }
        
        .status-validated {
            border-color: #28a745;
            color: #28a745;
        }
        
        .status-cancelled {
            border-color: #dc3545;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="dn-document invoice-document">
        <!-- En-tête du document -->
        @include('partials.document-header', [
            'documentNumber' => $deliveryNote->delivery_number,
            'documentDate' => $deliveryNote->delivery_date,
            'documentLabel' => 'Bon de livraison',
            'metaClass' => 'dn-meta invoice-meta',
        ])
        
        <!-- Informations fournisseur et livraison -->
        <div class="info-columns">
            <div class="column">
                <div class="column-title">Fournisseur</div>
                <div class="column-content">
                    @if($deliveryNote->supplier)
                        <p><strong>{{ $deliveryNote->supplier->name }}</strong></p>
                        @if($deliveryNote->supplier->address)
                            <p>{{ $deliveryNote->supplier->address }}</p>
                        @endif
                        @if($deliveryNote->supplier->phone)
                            <p>Téléphone: {{ $deliveryNote->supplier->phone }}</p>
                        @endif
                        @if($deliveryNote->supplier->email)
                            <p>Email: {{ $deliveryNote->supplier->email }}</p>
                        @endif
                    @else
                        <p><strong>Fournisseur non renseigné</strong></p>
                    @endif
                </div>
            </div>
            
            <div class="column">
                <div class="column-title">Informations</div>
                <div class="column-content">
                    <p><strong>Créé par:</strong> {{ $deliveryNote->user->name ?? 'Non renseigné' }}</p>
                    @if($deliveryNote->purchaseOrder)
                        <p><strong>Bon de commande:</strong> {{ $deliveryNote->purchaseOrder->po_number }}</p>
                    @endif
                    @if($deliveryNote->invoice_number)
                        <p><strong>Réf. facture fournisseur:</strong> {{ $deliveryNote->invoice_number }}</p>
                    @endif
                    <table style="width: 100%; border-collapse: collapse; margin: 3px 0;">
                        <tr>
                            <td style="padding: 0; vertical-align: middle; width: auto;">
                                <strong>Statut:</strong>
                            </td>
                            <td style="padding: 0 0 0 4px; vertical-align: middle;">
                                @php
                                    $statusLabels = [
                                        'pending' => 'En attente',
                                        'validated' => 'Validé',
                                        'cancelled' => 'Annulé'
                                    ];
                                    $statusClasses = [
                                        'pending' => 'status-pending',
                                        'validated' => 'status-validated',
                                        'cancelled' => 'status-cancelled'
                                    ];
                                @endphp
                                <span class="status-tag {{ $statusClasses[$deliveryNote->status] ?? 'status-pending' }}">
                                    {{ $statusLabels[$deliveryNote->status] ?? $deliveryNote->status }}
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Tableau des articles -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Désignation</th>
                    <th class="text-center">Quantité</th>
                    <th class="text-right">Prix unitaire</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deliveryNote->items as $item)
                <tr>
                    <td>
                        <span class="product-name-line">
                            <strong>{{ $item->product->name ?? 'Produit supprimé' }}</strong>@if($item->product && $item->product->sku)<span class="product-ref"> — Réf: {{ $item->product->sku }}</span>@endif
                        </span>
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">{{ format_currency($item->unit_price) }}</td>
                    <td class="text-right"><strong>{{ format_currency($item->total_price) }}</strong></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Totaux -->
        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td>Sous-total</td>
                    <td>{{ format_currency($deliveryNote->subtotal) }}</td>
                </tr>
                @if($deliveryNote->tax_amount > 0)
                <tr>
                    <td>Taxes</td>
                    <td>{{ format_currency($deliveryNote->tax_amount) }}</td>
                </tr>
                @endif
                @if($deliveryNote->discount_amount > 0)
                <tr class="discount">
                    <td>Remise</td>
                    <td>-{{ format_currency($deliveryNote->discount_amount) }}</td>
                </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL</td>
                    <td>{{ format_currency($deliveryNote->total_amount) }}</td>
                </tr>
            </table>
        </div>
        
        <!-- Informations additionnelles -->
        @if($deliveryNote->notes)
        <div class="additional-info">
            <div class="info-box">
                <div class="info-box-title">Notes</div>
                <div class="info-box-content">{{ $deliveryNote->notes }}</div>
            </div>
        </div>
        @endif
        
        @include('partials.document-signature-block', [
            'company' => $company,
            'showSignature' => $company->shouldPrintSignature(\App\Models\Company::DOCUMENT_DELIVERY_NOTE),
            'showStamp' => $company->shouldPrintStamp(\App\Models\Company::DOCUMENT_DELIVERY_NOTE),
        ])

        <!-- Footer -->
        <div class="document-footer">
            <p>Merci de votre collaboration | Document généré le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>
    </div>
</body>
</html>

