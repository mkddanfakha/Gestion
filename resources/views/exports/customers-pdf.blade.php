<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta charset="UTF-8">
    <title>Liste des clients</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #212529;
            line-height: 1.4;
            padding: 15px;
            background: #ffffff;
        }
        
        .header {
            border-bottom: 3px solid #212529;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .header h1 {
            font-size: 20px;
            color: #212529;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .header .company-info {
            font-size: 8px;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .header .date {
            font-size: 8px;
            color: #495057;
            margin-top: 5px;
        }
        
        .info-section {
            margin-bottom: 15px;
            font-size: 8px;
            color: #495057;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        thead {
            background: #212529;
            color: white;
        }
        
        th {
            padding: 8px 6px;
            text-align: left;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            border-right: 1px solid #495057;
        }
        
        th:last-child {
            border-right: none;
        }
        
        th.text-center {
            text-align: center;
        }
        
        th.text-right {
            text-align: right;
        }
        
        td {
            padding: 6px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            font-size: 8px;
            color: #212529;
        }
        
        td:last-child {
            border-right: none;
        }
        
        tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        tbody tr:last-child td {
            border-bottom: none;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border: 1px solid #212529;
            font-size: 7px;
            font-weight: bold;
            border-radius: 3px;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
            border-color: #155724;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
            border-color: #721c24;
        }
        
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
            font-size: 7px;
            color: #6c757d;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $company->name ?? 'Liste des clients' }}</h1>
        @if($company->tagline)
            <div class="company-info">{{ $company->tagline }}</div>
        @endif
        <div class="date">Généré le {{ now()->format('d/m/Y à H:i') }}</div>
    </div>

    <div class="info-section">
        <strong>Total de clients:</strong> {{ $customers->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">ID</th>
                <th style="width: 15%;">Nom</th>
                <th style="width: 15%;">Email</th>
                <th style="width: 12%;">Téléphone</th>
                <th style="width: 15%;">Adresse</th>
                <th style="width: 10%;">Ville</th>
                <th style="width: 8%;">Code postal</th>
                <th style="width: 10%;">Pays</th>
                <th class="text-right" style="width: 10%;">Limite crédit</th>
                <th class="text-center" style="width: 5%;">Statut</th>
                <th class="text-center" style="width: 5%;">Ventes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $customer)
                <tr>
                    <td>{{ $customer->id }}</td>
                    <td><strong>{{ $customer->name }}</strong></td>
                    <td>{{ $customer->email ?? '-' }}</td>
                    <td>{{ $customer->phone ?? '-' }}</td>
                    <td>{{ $customer->address ?? '-' }}</td>
                    <td>{{ $customer->city ?? '-' }}</td>
                    <td>{{ $customer->postal_code ?? '-' }}</td>
                    <td>{{ $customer->country ?? '-' }}</td>
                    <td class="text-right">{{ number_format($customer->credit_limit, 0, ',', ' ') }} FCFA</td>
                    <td class="text-center">
                        <span class="badge {{ $customer->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $customer->is_active ? 'Actif' : 'Inactif' }}
                        </span>
                    </td>
                    <td class="text-center">{{ $customer->sales_count ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="text-center">Aucun client trouvé</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par le système de gestion
    </div>
</body>
</html>

