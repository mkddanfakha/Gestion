<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Facture {{ $sale->sale_number }}</title>
    @include('partials.document-styles')
    <style>
        /* Styles spécifiques aux factures */
        .status-paid {
            border-color: #28a745;
            color: #28a745;
        }
        
        .status-partial {
            border-color: #ffc107;
            color: #856404;
        }
        
        .status-pending {
            border-color: #dc3545;
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="invoice-document">
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
                <div class="invoice-meta">
                    <div class="label">Facture N°</div>
                    <div class="value">{{ $sale->sale_number }}</div>
                    <div class="label" style="margin-top: 5px;">Date</div>
                    <div class="value">{{ $sale->created_at->format('d/m/Y') }}</div>
                </div>
            </div>
        </div>
        
        <!-- Informations client et vente -->
        <div class="info-columns">
            <div class="column">
                <div class="column-title">Client</div>
                <div class="column-content">
                    @if($sale->customer)
                        <p><strong>{{ $sale->customer->name }}</strong></p>
                        @if($sale->customer->address)
                            <p>{{ $sale->customer->address }}</p>
                        @endif
                        @if($sale->customer->phone)
                            <p>Téléphone: {{ $sale->customer->phone }}</p>
                        @endif
                        @if($sale->customer->email)
                            <p>Email: {{ $sale->customer->email }}</p>
                        @endif
                    @else
                        <p><strong>Client anonyme</strong></p>
                    @endif
                </div>
            </div>
            
            <div class="column">
                <div class="column-title">Informations</div>
                <div class="column-content">
                    <p><strong>Vendeur:</strong> {{ $sale->user->name ?? 'Non renseigné' }}</p>
                    <p><strong>Mode de paiement:</strong> 
                        @php
                            $paymentMethods = [
                                'cash' => 'Espèces',
                                'card' => 'Carte',
                                'bank_transfer' => 'Virement bancaire',
                                'check' => 'Chèque',
                                'orange_money' => 'Orange Money',
                                'wave' => 'Wave'
                            ];
                        @endphp
                        {{ $paymentMethods[$sale->payment_method] ?? $sale->payment_method }}
                    </p>
                    <table style="width: 100%; border-collapse: collapse; margin: 3px 0;">
                        <tr>
                            <td style="padding: 0; vertical-align: middle; width: auto;">
                                <strong>Statut:</strong>
                            </td>
                            <td style="padding: 0 0 0 4px; vertical-align: middle;">
                                @php
                                    $statusLabels = [
                                        'paid' => 'Payé',
                                        'partial' => 'Partiel',
                                        'pending' => 'En attente'
                                    ];
                                    $statusClasses = [
                                        'paid' => 'status-paid',
                                        'partial' => 'status-partial',
                                        'pending' => 'status-pending'
                                    ];
                                @endphp
                                <span class="status-tag {{ $statusClasses[$sale->payment_status ?? 'paid'] ?? 'status-paid' }}">
                                    {{ $statusLabels[$sale->payment_status ?? 'paid'] ?? 'Payé' }}
                                </span>
                            </td>
                        </tr>
                    </table>
                    @if($sale->due_date)
                        <p><strong>Date d'échéance:</strong> {{ \Carbon\Carbon::parse($sale->due_date)->format('d/m/Y') }}</p>
                    @endif
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
                @foreach($sale->saleItems as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product->name ?? 'Produit supprimé' }}</strong>
                        @if($item->product && $item->product->sku)
                            <br><span class="product-ref">Réf: {{ $item->product->sku }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }} {{ $item->product->unit ?? 'pce' }}</td>
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
                    <td>Sous-total HT</td>
                    <td>{{ number_format($sale->subtotal ?? $sale->total_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @if(($sale->tax_amount ?? 0) > 0)
                <tr>
                    <td>Taxes</td>
                    <td>{{ number_format($sale->tax_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                @if(($sale->discount_amount ?? 0) > 0)
                <tr class="discount">
                    <td>Remise</td>
                    <td>-{{ number_format($sale->discount_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                @if(($sale->down_payment_amount ?? 0) > 0)
                <tr>
                    <td>Acompte versé</td>
                    <td>{{ number_format($sale->down_payment_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                @if(($sale->remaining_amount ?? 0) > 0)
                <tr class="warning">
                    <td>Reste à payer</td>
                    <td>{{ number_format($sale->remaining_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL TTC</td>
                    <td>{{ number_format($sale->total_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
            </table>
        </div>
        
        <!-- Informations additionnelles -->
        <div class="additional-info">
            @if($sale->notes)
            <div class="info-box">
                <div class="info-box-title">Notes</div>
                <div class="info-box-content">{{ $sale->notes }}</div>
            </div>
            @endif
            
            @if(($sale->down_payment_amount ?? 0) > 0 || ($sale->remaining_amount ?? 0) > 0)
            <div class="info-box">
                <div class="info-box-title">Paiement</div>
                <div class="info-box-content">
                    @if(($sale->down_payment_amount ?? 0) > 0)
                        <p>Acompte: <strong>{{ number_format($sale->down_payment_amount, 0, ',', ' ') }} F</strong></p>
                    @endif
                    @if(($sale->remaining_amount ?? 0) > 0)
                        <p>Reste: <strong>{{ number_format($sale->remaining_amount, 0, ',', ' ') }} F</strong></p>
                    @endif
                </div>
            </div>
            @endif
        </div>
        
        <!-- Footer -->
        <div class="document-footer">
            <p>Merci de votre confiance | Document généré le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>
    </div>
</body>
</html>
