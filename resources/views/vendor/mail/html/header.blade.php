@props(['url'])
<tr>
<td class="header" style="background-color: #0d6efd; padding: 30px 0; text-align: center;">
<a href="{{ $url ?? config('app.url') }}" style="display: inline-block; text-decoration: none;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" style="padding: 0 20px;">
<div style="display: inline-block; vertical-align: middle;">
<img src="{{ config('app.url') }}/logo.png" alt="Logo Gestion" style="width: 60px; height: 60px; object-fit: contain; display: block; margin: 0 auto 10px;">
</div>
<div style="color: #ffffff; font-size: 24px; font-weight: bold; margin-top: 10px;">
Gestion
</div>
</td>
</tr>
</table>
</a>
</td>
</tr>

