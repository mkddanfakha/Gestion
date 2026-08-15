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

        .header h1 {
            font-size: 18px;
            color: #212529;
            margin-bottom: 4px;
            font-weight: bold;
        }

        .header .company-info {
            font-size: 8px;
            color: #6c757d;
            margin-top: 4px;
        }

        .header .date {
            font-size: 8px;
            color: #495057;
            margin-top: 4px;
        }

        .info-section {
            margin-bottom: 12px;
            font-size: 8px;
            color: #495057;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            table-layout: fixed;
        }

        thead {
            background: #212529;
            color: white;
        }

        th {
            padding: 6px 4px;
            text-align: left;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            border-right: 1px solid #495057;
            word-wrap: break-word;
        }

        th:last-child {
            border-right: none;
        }

        th.text-center {
            text-align: center;
        }

        td {
            padding: 5px 4px;
            border-bottom: 1px solid #dee2e6;
            border-right: 1px solid #dee2e6;
            font-size: 7px;
            color: #212529;
            vertical-align: top;
            word-wrap: break-word;
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

        .text-center {
            text-align: center;
        }

        .badge {
            display: inline-block;
            padding: 2px 5px;
            border: 1px solid #212529;
            font-size: 6px;
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
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #dee2e6;
            font-size: 7px;
            color: #6c757d;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>LISTE DES CLIENTS</h1>
        @if($company->tagline)
            <div class="company-info">{{ $company->name }} — {{ $company->tagline }}</div>
        @else
            <div class="company-info">{{ $company->name ?? 'MKD-Pro' }}</div>
        @endif
        <div class="date">Exporté le {{ now()->format('d/m/Y à H:i') }}</div>
    </div>

    <div class="info-section">
        <strong>Total de clients :</strong> {{ $rows->count() }}
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 4%;">ID</th>
                <th style="width: 14%;">Nom</th>
                <th style="width: 10%;">Téléphone</th>
                <th style="width: 12%;">Email</th>
                <th style="width: 9%;">Nationalité</th>
                <th style="width: 9%;">Pays résidence</th>
                <th style="width: 10%;">Type pièce</th>
                <th style="width: 12%;">N° pièce</th>
                <th class="text-center" style="width: 6%;">Statut</th>
                <th class="text-center" style="width: 5%;">Ventes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['id'] }}</td>
                    <td><strong>{{ $row['name'] }}</strong></td>
                    <td>{{ $row['phone'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td>{{ $row['nationality'] }}</td>
                    <td>{{ $row['country'] }}</td>
                    <td>{{ $row['identity_type'] }}</td>
                    <td>{{ $row['identity_number'] }}</td>
                    <td class="text-center">
                        <span class="badge {{ $row['status'] === 'Actif' ? 'badge-success' : 'badge-danger' }}">
                            {{ $row['status'] }}
                        </span>
                    </td>
                    <td class="text-center">{{ $row['sales_count'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center">Aucun client trouvé</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Document généré automatiquement par le système de gestion
    </div>
</body>
</html>
