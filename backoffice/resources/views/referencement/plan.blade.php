{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $page)
    <url>
        <loc>{{ $page['adresse'] }}</loc>
        <lastmod>{{ $jour }}</lastmod>
        <changefreq>{{ $page['frequence'] }}</changefreq>
        <priority>{{ $page['priorite'] }}</priority>
    </url>
@endforeach
</urlset>
