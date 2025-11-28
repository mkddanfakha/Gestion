<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Devis {{ $quote->quote_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #212529;
            line-height: 1.4;
            padding: 15px;
            background: #ffffff;
        }
        
        .quote-document {
            max-width: 100%;
            margin: 0 auto;
            border: 2px solid #212529;
            padding: 15px;
        }
        
        /* En-tête avec ligne décorative */
        .document-header {
            border-bottom: 4px double #212529;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .logo-section {
            flex: 1;
        }
        
        .logo-section h1 {
            font-size: 22px;
            color: #212529;
            margin-bottom: 3px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .logo-section .tagline {
            font-size: 8px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .logo-section .company-details {
            font-size: 8px;
            color: #495057;
            margin-top: 5px;
            line-height: 1.5;
        }
        
        .logo-section .company-details p {
            margin: 2px 0;
        }
        
        .quote-meta {
            text-align: right;
            border: 2px solid #212529;
            padding: 8px 12px;
            background: #f8f9fa;
        }
        
        .quote-meta .label {
            font-size: 8px;
            text-transform: uppercase;
            color: #6c757d;
            letter-spacing: 0.5px;
        }
        
        .quote-meta .value {
            font-size: 14px;
            font-weight: bold;
            color: #212529;
            margin-top: 2px;
        }
        
        /* Informations en colonnes */
        .info-columns {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
        }
        
        .column {
            flex: 1;
        }
        
        .column-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            color: #212529;
            margin-bottom: 6px;
            padding-bottom: 3px;
            border-bottom: 2px solid #212529;
            letter-spacing: 0.5px;
        }
        
        .column-content {
            font-size: 9px;
            color: #495057;
        }
        
        .column-content p {
            margin: 3px 0;
            line-height: 1.6;
        }
        
        .column-content strong {
            color: #212529;
        }
        
        /* Tableau classique */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            border: 1px solid #212529;
        }
        
        .items-table thead {
            background: #212529;
            color: white;
        }
        
        .items-table th {
            padding: 8px 6px;
            text-align: left;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid #495057;
        }
        
        .items-table th:last-child {
            border-right: none;
        }
        
        .items-table th.text-center {
            text-align: center;
        }
        
        .items-table th.text-right {
            text-align: right;
        }
        
        .items-table td {
            padding: 6px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            font-size: 9px;
            color: #212529;
        }
        
        .items-table td:last-child {
            border-right: none;
        }
        
        .items-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .items-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        /* Totaux en tableau */
        .totals-wrapper {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }
        
        .totals-table {
            width: 300px;
            border-collapse: collapse;
            border: 1px solid #212529;
        }
        
        .totals-table td {
            padding: 5px 8px;
            font-size: 9px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .totals-table td:first-child {
            text-align: right;
            font-weight: 500;
            color: #495057;
            width: 60%;
        }
        
        .totals-table td:last-child {
            text-align: right;
            font-weight: 600;
            color: #212529;
            border-left: 1px solid #dee2e6;
        }
        
        .totals-table tr:last-child td {
            border-bottom: none;
        }
        
        .totals-table tr.grand-total {
            background: #212529;
            color: white;
        }
        
        .totals-table tr.grand-total td {
            padding: 8px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            border: none;
        }
        
        .totals-table tr.discount td:last-child {
            color: #dc3545;
        }
        
        /* Statut */
        .status-tag {
            display: inline-block;
            padding: 2px 6px;
            border: 1px solid #212529;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            background: white;
            line-height: 1.4;
        }
        
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
        
        /* Notes et informations additionnelles */
        .additional-info {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            margin-bottom: 10px;
        }
        
        .info-box {
            flex: 1;
            padding: 8px;
            border: 1px solid #212529;
            background: #f8f9fa;
        }
        
        .info-box-title {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #212529;
            margin-bottom: 5px;
            padding-bottom: 3px;
            border-bottom: 1px solid #212529;
        }
        
        .info-box-content {
            font-size: 9px;
            color: #495057;
        }
        
        /* Footer */
        .document-footer {
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #212529;
            text-align: center;
            font-size: 8px;
            color: #6c757d;
        }
        
        .product-ref {
            font-size: 7px;
            color: #6c757d;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="quote-document">
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
                <div class="quote-meta">
                    <div class="label">Devis N°</div>
                    <div class="value">{{ $quote->quote_number }}</div>
                    <div class="label" style="margin-top: 5px;">Date</div>
                    <div class="value">{{ $quote->created_at->format('d/m/Y') }}</div>
                </div>
            </div>
        </div>
        
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
                    <td>{{ number_format($quote->subtotal ?? $quote->total_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @if(($quote->tax_amount ?? 0) > 0)
                <tr>
                    <td>Taxes</td>
                    <td>{{ number_format($quote->tax_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                @if(($quote->discount_amount ?? 0) > 0)
                <tr class="discount">
                    <td>Remise</td>
                    <td>-{{ number_format($quote->discount_amount, 0, ',', ' ') }} Fcfa</td>
                </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL TTC</td>
                    <td>{{ number_format($quote->total_amount, 0, ',', ' ') }} Fcfa</td>
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

