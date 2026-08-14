@php
    $showSignatureImage = ($showSignature ?? false) && $company->signature_absolute_path;
    $showStampImage = ($showStamp ?? false) && $company->stamp_absolute_path;
@endphp

@if($showSignatureImage || $showStampImage)
    <div class="document-signature-section">
        <table class="document-signature-table">
            <tr>
                @if($showSignatureImage)
                    <td class="document-signature-cell">
                        <div class="document-signature-label">Signature</div>
                        <img
                            src="{{ $company->signature_absolute_path }}"
                            class="company-signature"
                            alt="Signature {{ $company->name }}"
                        >
                    </td>
                @endif
                @if($showStampImage)
                    <td class="document-signature-cell">
                        <div class="document-signature-label">Cachet</div>
                        <img
                            src="{{ $company->stamp_absolute_path }}"
                            class="company-stamp"
                            alt="Cachet {{ $company->name }}"
                        >
                    </td>
                @endif
            </tr>
        </table>
    </div>
@endif
