@php
    $documentNumber = $documentNumber ?? '';
    $documentDate = $documentDate ?? now();
    $documentLabel = $documentLabel ?? 'Document';
@endphp

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
            <div class="label">{{ $documentLabel }} N°</div>
            <div class="value">{{ $documentNumber }}</div>
            <div class="label" style="margin-top: 5px;">Date</div>
            <div class="value">{{ is_string($documentDate) ? $documentDate : $documentDate->format('d/m/Y') }}</div>
        </div>
    </div>
</div>

