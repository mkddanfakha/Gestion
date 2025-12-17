<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Bon de commande {{ $purchaseOrder->po_number }}</title>
    @include('partials.document-styles')
    <style>
        /* Styles spécifiques aux bons de commande */
        /* Compatibilité avec les anciennes classes */
        .po-document,
        .po-meta {
            /* Utilise les styles de invoice-document et invoice-meta du partial */
        }
        
        /* Statuts spécifiques aux bons de commande */
        .status-draft {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .status-sent {
            border-color: #17a2b8;
            color: #17a2b8;
        }
        
        .status-confirmed {
            border-color: #ffc107;
            color: #856404;
        }
        
        .status-partially_received {
            border-color: #007bff;
            color: #007bff;
        }
        
        .status-received {
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
    <div class="po-document invoice-document">
        <!-- En-tête du document -->
        <div class="document-header">
            <div class="header-top">
                <div class="logo-section">
                    <h1>{{ $company->name }}</h1>
                    @if($company->tagline)
                        <div class="tagline">{{ $company->tagline }}</div>
                    @endif
                    <div class="company-details">
                        @if($company->address)
                            <p><strong>Adresse:</strong> {{ $company->address }}</p>
                        @endif
                        @php
                            $phones = array_filter([$company->phone1, $company->phone2, $company->phone3]);
                        @endphp
                        @if(!empty($phones) || $company->email)
                            <p>
                                @if(!empty($phones))
                                    <strong>Téléphone{{ count($phones) > 1 ? 's' : '' }}:</strong> {{ implode(' | ', $phones) }}
                                @endif
                                @if(!empty($phones) && $company->email)
                                    | 
                                @endif
                                @if($company->email)
                                    <strong>Email:</strong> {{ $company->email }}
                                @endif
                            </p>
                        @endif
                        @if($company->rc_number || $company->ncc_number)
                            <p>
                                @if($company->rc_number)
                                    <strong>RC:</strong> {{ $company->rc_number }}
                                @endif
                                @if($company->rc_number && $company->ncc_number)
                                    | 
                                @endif
                                @if($company->ncc_number)
                                    <strong>NCC:</strong> {{ $company->ncc_number }}
                                @endif
                            </p>
                        @endif
                    </div>
                </div>
                <div class="po-meta invoice-meta">
                    <div class="label">Bon de commande N°</div>
                    <div class="value">{{ $purchaseOrder->po_number }}</div>
                    <div class="label" style="margin-top: 5px;">Date</div>
                    <div class="value">{{ $purchaseOrder->order_date->format('d/m/Y') }}</div>
                </div>
            </div>
        </div>
        
        <!-- Informations fournisseur et commande -->
        <div class="info-columns">
            <div class="column">
                <div class="column-title">Fournisseur</div>
                <div class="column-content">
                    @if($purchaseOrder->supplier)
                        <p><strong>{{ $purchaseOrder->supplier->name }}</strong></p>
                        @if($purchaseOrder->supplier->address)
                            <p>{{ $purchaseOrder->supplier->address }}</p>
                        @endif
                        @if($purchaseOrder->supplier->phone)
                            <p>Téléphone: {{ $purchaseOrder->supplier->phone }}</p>
                        @endif
                        @if($purchaseOrder->supplier->email)
                            <p>Email: {{ $purchaseOrder->supplier->email }}</p>
                        @endif
                    @else
                        <p><strong>Fournisseur non renseigné</strong></p>
                    @endif
                </div>
            </div>
            
            <div class="column">
                <div class="column-title">Informations</div>
                <div class="column-content">
                    <p><strong>Créé par:</strong> {{ $purchaseOrder->user->name ?? 'Non renseigné' }}</p>
                    @if($purchaseOrder->expected_delivery_date)
                        <p><strong>Date de livraison prévue:</strong> {{ $purchaseOrder->expected_delivery_date->format('d/m/Y') }}</p>
                    @endif
                    <table style="width: 100%; border-collapse: collapse; margin: 3px 0;">
                        <tr>
                            <td style="padding: 0; vertical-align: middle; width: auto;">
                                <strong>Statut:</strong>
                            </td>
                            <td style="padding: 0 0 0 4px; vertical-align: middle;">
                                @php
                                    $statusLabels = [
                                        'draft' => 'Brouillon',
                                        'sent' => 'Envoyé',
                                        'confirmed' => 'Confirmé',
                                        'partially_received' => 'Partiellement reçu',
                                        'received' => 'Reçu',
                                        'cancelled' => 'Annulé'
                                    ];
                                    $statusClasses = [
                                        'draft' => 'status-draft',
                                        'sent' => 'status-sent',
                                        'confirmed' => 'status-confirmed',
                                        'partially_received' => 'status-partially_received',
                                        'received' => 'status-received',
                                        'cancelled' => 'status-cancelled'
                                    ];
                                @endphp
                                <span class="status-tag {{ $statusClasses[$purchaseOrder->status] ?? 'status-draft' }}">
                                    {{ $statusLabels[$purchaseOrder->status] ?? $purchaseOrder->status }}
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
                @foreach($purchaseOrder->items as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product->name ?? 'Produit supprimé' }}</strong>
                        @if($item->product && $item->product->sku)
                            <br><span class="product-ref">Réf: {{ $item->product->sku }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">{{ number_format($item->unit_price, 0, ',', ' ') }} F</td>
                    <td class="text-right"><strong>{{ number_format($item->total_price, 0, ',', ' ') }} F</strong></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        
        <!-- Totaux -->
        <div class="totals-wrapper">
            <table class="totals-table">
                <tr>
                    <td>Sous-total</td>
                    <td>{{ number_format($purchaseOrder->subtotal, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @if($purchaseOrder->tax_amount > 0)
                <tr>
                    <td>Taxes</td>
                    <td>{{ number_format($purchaseOrder->tax_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                @if($purchaseOrder->discount_amount > 0)
                <tr class="discount">
                    <td>Remise</td>
                    <td>-{{ number_format($purchaseOrder->discount_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL</td>
                    <td>{{ number_format($purchaseOrder->total_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
            </table>
        </div>
        
        <!-- Informations additionnelles -->
        @if($purchaseOrder->notes)
        <div class="additional-info">
            <div class="info-box">
                <div class="info-box-title">Notes</div>
                <div class="info-box-content">{{ $purchaseOrder->notes }}</div>
            </div>
        </div>
        @endif
        
        <!-- Footer -->
        <div class="document-footer">
            <p>Merci de votre collaboration | Document généré le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>
    </div>
</body>
</html>

