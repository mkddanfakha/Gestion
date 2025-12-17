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
    
    .invoice-document {
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
    
    .invoice-meta {
        text-align: right;
        border: 2px solid #212529;
        padding: 8px 12px;
        background: #f8f9fa;
    }
    
    .invoice-meta .label {
        font-size: 8px;
        text-transform: uppercase;
        color: #6c757d;
        letter-spacing: 0.5px;
    }
    
    .invoice-meta .value {
        font-size: 14px;
        font-weight: bold;
        color: #212529;
        margin-top: 2px;
    }
    
    /* Classes de compatibilité pour les différents types de documents */
    .quote-document,
    .dn-document,
    .po-document {
        /* Utilise les styles de invoice-document */
    }
    
    .quote-meta,
    .dn-meta,
    .po-meta {
        /* Utilise les styles de invoice-meta */
        text-align: right;
        border: 2px solid #212529;
        padding: 8px 12px;
        background: #f8f9fa;
    }
    
    .quote-meta .label,
    .quote-meta .value,
    .dn-meta .label,
    .dn-meta .value,
    .po-meta .label,
    .po-meta .value {
        /* Utilise les styles de invoice-meta */
    }
    
    .quote-meta .label,
    .dn-meta .label,
    .po-meta .label {
        font-size: 8px;
        text-transform: uppercase;
        color: #6c757d;
        letter-spacing: 0.5px;
    }
    
    .quote-meta .value,
    .dn-meta .value,
    .po-meta .value {
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
        background: #f8f9fa;
        color: #6c757d;
    }
    
    .items-table th {
        padding: 8px 6px;
        text-align: left;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-right: 1px solid #dee2e6;
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
        background: #f8f9fa;
        color: #495057;
    }
    
    .totals-table tr.grand-total td {
        padding: 8px;
        font-size: 11px;
        font-weight: bold;
        color: #495057;
        border: none;
    }
    
    .totals-table tr.discount td:last-child {
        color: #dc3545;
    }
    
    .totals-table tr.warning td:last-child {
        color: #fd7e14;
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

