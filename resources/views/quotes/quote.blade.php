<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Devis {{ $quote->quote_number }}</title>
    @include('partials.document-styles')
    <style>
        /* Styles spécifiques aux devis */
        /* Compatibilité avec les anciennes classes */
        .quote-document,
        .quote-meta {
            /* Utilise les styles de invoice-document et invoice-meta du partial */
        }
        
        /* Statuts spécifiques aux devis */
        .status-draft {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .status-sent {
            border-color: #17a2b8;
            color: #17a2b8;
        }
        
        .status-accepted {
            border-color: #28a745;
            color: #28a745;
        }
        
        .status-rejected {
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .status-expired {
            border-color: #ffc107;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="quote-document invoice-document">
        <!-- En-tête du document -->
        @include('partials.document-header', [
            'documentNumber' => $quote->quote_number,
            'documentDate' => $quote->created_at,
            'documentLabel' => 'Devis',
            'metaClass' => 'quote-meta invoice-meta',
        ])
        
        <!-- Informations client et devis -->
        <div class="info-columns">
            <div class="column">
                <div class="column-title">Client</div>
                <div class="column-content">
                    @if($quote->customer)
                        <p><strong>{{ $quote->customer->name }}</strong></p>
                        @if($quote->customer->address)
                            <p>{{ $quote->customer->address }}</p>
                        @endif
                        @if($quote->customer->phone)
                            <p>Téléphone: {{ $quote->customer->phone }}</p>
                        @endif
                        @if($quote->customer->email)
                            <p>Email: {{ $quote->customer->email }}</p>
                        @endif
                    @else
                        <p><strong>Client anonyme</strong></p>
                    @endif
                </div>
            </div>
            
            <div class="column">
                <div class="column-title">Informations</div>
                <div class="column-content">
                    <p><strong>Créé par:</strong> {{ $quote->user->name ?? 'Non renseigné' }}</p>
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
                                        'accepted' => 'Accepté',
                                        'rejected' => 'Refusé',
                                        'expired' => 'Expiré'
                                    ];
                                    $statusClasses = [
                                        'draft' => 'status-draft',
                                        'sent' => 'status-sent',
                                        'accepted' => 'status-accepted',
                                        'rejected' => 'status-rejected',
                                        'expired' => 'status-expired'
                                    ];
                                @endphp
                                <span class="status-tag {{ $statusClasses[$quote->status] ?? 'status-draft' }}">
                                    {{ $statusLabels[$quote->status] ?? 'Brouillon' }}
                                </span>
                            </td>
                        </tr>
                    </table>
                    @if($quote->valid_until)
                        <p><strong>Date de validité:</strong> {{ \Carbon\Carbon::parse($quote->valid_until)->format('d/m/Y') }}</p>
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
                @foreach($quote->quoteItems as $item)
                <tr>
                    <td>
                        <strong>{{ $item->product->name ?? 'Produit supprimé' }}</strong>
                        @if($item->product && $item->product->sku)
                            <br><span class="product-ref">Réf: {{ $item->product->sku }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $item->quantity }} {{ $item->product->unit ?? 'pce' }}</td>
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
                    <td>Sous-total HT</td>
                    <td>{{ format_currency($quote->subtotal ?? $quote->total_amount) }}</td>
                </tr>
                @if(($quote->tax_amount ?? 0) > 0)
                <tr>
                    <td>Taxes</td>
                    <td>{{ format_currency($quote->tax_amount) }}</td>
                </tr>
                @endif
                @if(($quote->discount_amount ?? 0) > 0)
                <tr class="discount">
                    <td>Remise</td>
                    <td>-{{ format_currency($quote->discount_amount) }}</td>
                </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL TTC</td>
                    <td>{{ format_currency($quote->total_amount) }}</td>
                </tr>
            </table>
        </div>
        
        <!-- Informations additionnelles -->
        @if($quote->notes)
        <div class="additional-info">
            <div class="info-box">
                <div class="info-box-title">Notes</div>
                <div class="info-box-content">{{ $quote->notes }}</div>
            </div>
        </div>
        @endif
        
        <!-- Footer -->
        <div class="document-footer">
            <p>Merci de votre confiance | Document généré le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>
    </div>
</body>
</html>

