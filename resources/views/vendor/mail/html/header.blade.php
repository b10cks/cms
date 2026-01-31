@props(['url'])
<a href="{{ $url }}" style="display: inline-block;">
<img
src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('resources/mail_logo.png')))  }}" class="logo"
alt="{{ config('app.name') }}"
>
</a>
<br>
<br>
