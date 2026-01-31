<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
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

<a href="https://www.sabaccui.com" style="display: block;">
<img
src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/mail_logo.png')))  }}" class="logo"
alt="{{ config('app.name') }}"
>
</a>
<br>

{{ trans('notifications.footer') }}
<br>
<br>

### Follow us
<table class="footer-social-nav" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="https://www.twitter.com/_mwallner" target="_blank">
<img width="24" height="24" src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/twitter.png')))  }}" alt="Twitter">
</a>
</td>
<td>
<a href="https://www.linkedin.com/in/neonblack-mwallner" target="_blank">
<img width="24" height="24" src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/linkedin.png')))  }}" alt="Michael Wallner on LinkedIn">
</a>
</td>
<td>
<a href="https://www.youtube.com/@coderscantina" target="_blank">
<img width="24" height="24" src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/youtube.png')))  }}" alt="Coder's Cantina Channel on Youtube">
</a>
</td>
<td>
<a href="https://www.github.com/sabaccui" target="_blank">
<img width="24" height="24" src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/github.png')))  }}" alt="SabaccUI on GitHub">
</a>
</td>
</tr>
</table>

{{ trans('notifications.footerImprint') }}

© {{ date('Y') }} {{ trans('notifications.footerCopyright') }} • <a href="https://www.sabaccui.com/imprint" target="_blank">Imprint</a> • <a href="https://www.sabaccui.com/data-privacy" target="_blank">Data Privacy</a>

</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
