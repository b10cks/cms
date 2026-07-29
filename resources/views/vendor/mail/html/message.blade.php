<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.frontend_url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{{ $slot }}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>

<a href="{{ config('app.frontend_url') }}" style="display: block;">
<img
src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/mail_logo.png')))  }}" class="logo"
alt="{{ config('app.name') }}"
>
</a>
<br>

@if (\App\Support\EditionGate::isSaas())
{{ trans('notifications.footer') }}
<br>
<br>

{{ trans('notifications.footerImprint') }}

© {{ date('Y') }} {{ trans('notifications.footerCopyright') }}<br>
<a href="https://www.b10cks.com/en/legal/imprint" target="_blank">Imprint</a>
 • <a href="https://www.b10cks.com/en/legal/dpa" target="_blank">Data Privacy Agreement</a>
 • <a href="https://www.b10cks.com/en/legal/privacy-policy" target="_blank">Privacy Policy</a>
 • <a href="https://www.b10cks.com/en/legal/terms-of-service" target="_blank">Terms of Use</a>
@else
@if (config('edition.imprint.company'))
{{ config('edition.imprint.company') }}@if (config('edition.imprint.address')), {{ config('edition.imprint.address') }}@endif

@endif
@if (config('edition.imprint.notice'))
{{ config('edition.imprint.notice') }}

@endif
© {{ date('Y') }} {{ config('edition.imprint.company') ?: config('app.name') }}
@endif

</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
