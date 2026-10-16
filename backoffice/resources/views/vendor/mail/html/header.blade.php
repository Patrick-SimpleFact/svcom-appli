@props(['url'])
{{-- En-tête des e-mails : le logo Spettacoli (PNG transparent, l'SVG n'est pas lu par Gmail ni Outlook) au lieu du nom de l'application. --}}
<tr>
<td class="header">
<a href="{{ config('app.url_site') }}" style="display: inline-block;">
<img src="{{ config('app.url_site') }}/images/logo-email.png" alt="Spettacoli" width="188" height="64" style="width: 188px; height: auto; border: 0;">
</a>
</td>
</tr>
