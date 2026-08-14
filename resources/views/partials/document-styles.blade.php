<style>
    @page {
        size: A4 portrait;
        margin: 10mm 12mm;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10px;
        color: #212529;
        line-height: 1.35;
        padding: 0;
        background: #ffffff;
    }

    .invoice-document,
    .quote-document,
    .dn-document,
    .po-document {
        max-width: 100%;
        margin: 0 auto;
        border: 1px solid #212529;
        padding: 8px 10px;
    }

    /* En-tête */
    .document-header {
        border-bottom: 2px solid #212529;
        padding-bottom: 6px;
        margin-bottom: 8px;
    }

    .header-top {
        display: table;
        width: 100%;
        table-layout: fixed;
    }

    .logo-section {
        display: table-cell;
        vertical-align: top;
        width: 68%;
        padding-right: 8px;
    }

    .logo-section-inner {
        display: table;
        width: 100%;
    }

    .company-logo-wrap {
        display: table-cell;
        vertical-align: top;
        width: 92px;
        padding-right: 8px;
    }

    .company-logo {
        max-height: 48px;
        max-width: 92px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: block;
    }

    .company-identity {
        display: table-cell;
        vertical-align: top;
    }

    .logo-section h1 {
        font-size: 18px;
        color: #212529;
        margin-bottom: 2px;
        font-weight: bold;
        letter-spacing: 0.5px;
        line-height: 1.15;
    }

    .logo-section .tagline {
        font-size: 8px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 3px;
    }

    .logo-section .company-details {
        font-size: 8px;
        color: #495057;
        margin-top: 2px;
        line-height: 1.35;
    }

    .logo-section .company-details p {
        margin: 1px 0;
    }

    .invoice-meta,
    .quote-meta,
    .dn-meta,
    .po-meta {
        display: table-cell;
        vertical-align: top;
        width: 32%;
        text-align: right;
        border: 1px solid #212529;
        padding: 6px 8px;
        background: #f8f9fa;
    }

    .invoice-meta .label,
    .quote-meta .label,
    .dn-meta .label,
    .po-meta .label {
        font-size: 8px;
        text-transform: uppercase;
        color: #6c757d;
        letter-spacing: 0.4px;
    }

    .invoice-meta .value,
    .quote-meta .value,
    .dn-meta .value,
    .po-meta .value {
        font-size: 12px;
        font-weight: bold;
        color: #212529;
        margin-top: 1px;
        line-height: 1.2;
    }

    /* Informations en colonnes */
    .info-columns {
        display: table;
        width: 100%;
        table-layout: fixed;
        margin-bottom: 8px;
    }

    .column {
        display: table-cell;
        vertical-align: top;
        width: 50%;
        padding-right: 10px;
    }

    .column:last-child {
        padding-right: 0;
        padding-left: 10px;
    }

    .column-title {
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        color: #212529;
        margin-bottom: 4px;
        padding-bottom: 2px;
        border-bottom: 1px solid #212529;
        letter-spacing: 0.4px;
    }

    .column-content {
        font-size: 9px;
        color: #495057;
    }

    .column-content p {
        margin: 2px 0;
        line-height: 1.4;
    }

    .column-content strong {
        color: #212529;
    }

    /* Tableau des articles */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
        border: 1px solid #212529;
        table-layout: fixed;
    }

    .items-table thead {
        display: table-header-group;
    }

    .items-table thead th {
        background: #f8f9fa;
        color: #6c757d;
    }

    .items-table th {
        padding: 5px 4px;
        text-align: left;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-right: 1px solid #dee2e6;
        border-bottom: 1px solid #212529;
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

    .items-table th:first-child,
    .items-table td:first-child {
        width: 48%;
    }

    .items-table th:nth-child(2),
    .items-table td:nth-child(2) {
        width: 14%;
    }

    .items-table th:nth-child(3),
    .items-table td:nth-child(3) {
        width: 19%;
    }

    .items-table th:nth-child(4),
    .items-table td:nth-child(4) {
        width: 19%;
    }

    .items-table td {
        padding: 4px;
        border-bottom: 1px solid #dee2e6;
        border-right: 1px solid #dee2e6;
        font-size: 9px;
        color: #212529;
        vertical-align: top;
        word-wrap: break-word;
    }

    .items-table td:nth-child(2),
    .items-table td:nth-child(3),
    .items-table td:nth-child(4) {
        white-space: nowrap;
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

    /* Bas de document : totaux, notes, signature, footer */
    .document-bottom {
        margin-top: 2px;
    }

    .totals-wrapper {
        display: block;
        text-align: right;
        margin-bottom: 6px;
        page-break-inside: avoid;
    }

    .totals-table {
        width: 280px;
        border-collapse: collapse;
        border: 1px solid #212529;
        margin-left: auto;
    }

    .totals-table td {
        padding: 3px 6px;
        font-size: 9px;
        border-bottom: 1px solid #dee2e6;
        line-height: 1.25;
    }

    .totals-table td:first-child {
        text-align: right;
        font-weight: 500;
        color: #495057;
        width: 58%;
    }

    .totals-table td:last-child {
        text-align: right;
        font-weight: 600;
        color: #212529;
        border-left: 1px solid #dee2e6;
        white-space: nowrap;
    }

    .totals-table tr:last-child td {
        border-bottom: none;
    }

    .totals-table tr.grand-total {
        background: #f8f9fa;
    }

    .totals-table tr.grand-total td {
        padding: 5px 6px;
        font-size: 10px;
        font-weight: bold;
        color: #212529;
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
        padding: 1px 5px;
        border: 1px solid #212529;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        background: white;
        line-height: 1.3;
    }

    /* Notes */
    .additional-info {
        margin-bottom: 6px;
    }

    .info-box {
        padding: 5px 6px;
        border: 1px solid #212529;
        background: #f8f9fa;
    }

    .info-box-title {
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        color: #212529;
        margin-bottom: 3px;
        padding-bottom: 2px;
        border-bottom: 1px solid #212529;
    }

    .info-box-content {
        font-size: 9px;
        color: #495057;
        line-height: 1.35;
    }

    /* Signature / cachet */
    .document-signature-section {
        margin-top: 8px;
        padding-top: 6px;
        border-top: 1px solid #dee2e6;
        page-break-inside: avoid;
    }

    .document-signature-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .document-signature-cell {
        width: 50%;
        vertical-align: bottom;
        text-align: center;
        padding: 0 6px;
    }

    .document-signature-label {
        font-size: 8px;
        color: #495057;
        font-weight: 600;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .company-signature {
        max-width: 130px;
        max-height: 45px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: inline-block;
    }

    .company-stamp {
        max-width: 70px;
        max-height: 70px;
        width: auto;
        height: auto;
        object-fit: contain;
        display: inline-block;
    }

    /* Footer */
    .document-footer {
        margin-top: 6px;
        padding-top: 5px;
        border-top: 1px solid #212529;
        text-align: center;
        font-size: 8px;
        color: #6c757d;
        line-height: 1.3;
    }

    .product-ref {
        font-size: 7px;
        color: #6c757d;
        font-style: italic;
        white-space: nowrap;
    }

    .product-name-line {
        line-height: 1.3;
    }
</style>
