{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# Oups !
@else
# Bonjour !
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<div style="text-align: center; margin: 30px 0;">
<a href="{{ $actionUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #0d6efd; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold;">
{{ $actionText }}
</a>
</div>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Cordialement,<br>
{{ config('app.name') }}
@endif
