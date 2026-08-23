<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Rapport d'inventaire {{ $meta['reference'] ?? '' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8px;
            color: #212529;
            line-height: 1.35;
            padding: 12px;
            background: #ffffff;
        }
        .header {
            border-bottom: 3px solid #212529;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .header h1 { font-size: 18px; margin-bottom: 4px; font-weight: bold; }
        .header .company-info { font-size: 8px; color: #6c757d; margin-top: 4px; }
        .header .date { font-size: 8px; color: #495057; margin-top: 4px; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            margin: 14px 0 8px;
            text-transform: uppercase;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 4px;
        }
        .meta-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .meta-grid td {
            padding: 4px 6px;
            vertical-align: top;
            border-bottom: 1px solid #f1f3f5;
        }
        .meta-grid td.label {
            width: 28%;
            color: #6c757d;
            font-weight: bold;
        }
        .kpi-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .kpi-grid td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: center;
            width: 16.66%;
        }
        .kpi-grid .value { font-size: 14px; font-weight: bold; display: block; margin-top: 4px; }
        .kpi-grid .label { font-size: 7px; color: #6c757d; text-transform: uppercase; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            table-layout: fixed;
        }
        table.data thead { background: #212529; color: #fff; }
        table.data th {
            padding: 5px 4px;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            border-right: 1px solid #495057;
            word-wrap: break-word;
        }
        table.data td {
            padding: 4px;
            border-bottom: 1px solid #dee2e6;
            word-wrap: break-word;
            vertical-align: top;
        }
        table.data tbody tr:nth-child(even) { background: #f8f9fa; }
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header">
        <h1>RAPPORT D'INVENTAIRE</h1>
        <div class="company-info">
            @if($company ?? null)
                <strong>{{ $company->name }}</strong>
                @if($company->address) · {{ $company->address }} @endif
                @if($company->phone) · {{ $company->phone }} @endif
                @if($company->email) · {{ $company->email }} @endif
            @endif
        </div>
        <div class="date">Généré le {{ $generatedAt }}</div>
    </div>

    <div class="section-title">Informations générales</div>
    <table class="meta-grid">
        <tr><td class="label">Référence</td><td>{{ $meta['reference'] ?? '—' }}</td></tr>
        <tr><td class="label">Nom</td><td>{{ $meta['name'] ?? '—' }}</td></tr>
        <tr><td class="label">Description</td><td>{{ $meta['description'] ?? '—' }}</td></tr>
        <tr><td class="label">Statut</td><td>{{ $meta['status_label'] ?? '—' }}</td></tr>
        <tr><td class="label">Date</td><td>{{ $meta['session_date'] ?? '—' }}</td></tr>
        <tr><td class="label">Utilisateur</td><td>{{ $meta['created_by'] ?? '—' }}</td></tr>
        <tr><td class="label">Périmètre</td><td>{{ $meta['scope_label'] ?? '—' }}</td></tr>
        <tr><td class="label">Catégorie</td><td>{{ $meta['category_name'] ?? '—' }}</td></tr>
        <tr><td class="label">Magasin</td><td>{{ $meta['store_name'] ?? '—' }}</td></tr>
    </table>

    <div class="section-title">Résumé KPI</div>
    <table class="kpi-grid">
        <tr>
            <td><span class="label">Produits</span><span class="value">{{ $kpi['products'] ?? 0 }}</span></td>
            <td><span class="label">Comptés</span><span class="value">{{ $kpi['counted'] ?? 0 }}</span></td>
            <td><span class="label">Conformes</span><span class="value">{{ $kpi['conforme'] ?? 0 }}</span></td>
            <td><span class="label">Surplus</span><span class="value">{{ $kpi['surplus'] ?? 0 }}</span></td>
            <td><span class="label">Manquants</span><span class="value">{{ $kpi['manquants'] ?? 0 }}</span></td>
            <td><span class="label">Écart net</span><span class="value">{{ $kpi['net_variance_label'] ?? 0 }}</span></td>
        </tr>
    </table>

    <div class="section-title">Détail des produits</div>
    <table class="data">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Code-barres</th>
                <th class="text-center">Stock théorique</th>
                <th class="text-center">Compté</th>
                <th class="text-center">Écart</th>
                <th>Type</th>
            </tr>
        </thead>
        <tbody>
            @forelse($detailRows as $row)
                <tr>
                    <td>{{ $row['product_name'] }}</td>
                    <td>{{ $row['barcode'] ?? '—' }}</td>
                    <td class="text-center">{{ $row['stock_snapshot'] }}</td>
                    <td class="text-center">{{ $row['quantity_counted'] ?? '—' }}</td>
                    <td class="text-center">{{ $row['difference_label'] }}</td>
                    <td>{{ $row['variance_label'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center">Aucun produit.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if(count($movementRows) > 0)
        <div class="page-break"></div>
        <div class="section-title">Mouvements de stock</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Type</th>
                    <th class="text-center">Quantité</th>
                    <th>Date</th>
                    <th>Utilisateur</th>
                </tr>
            </thead>
            <tbody>
                @foreach($movementRows as $row)
                    <tr>
                        <td>{{ $row['product_name'] }}</td>
                        <td>{{ $row['type_label'] }}</td>
                        <td class="text-center">{{ $row['quantity_label'] }}</td>
                        <td>{{ $row['created_at'] }}</td>
                        <td>{{ $row['user_name'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
