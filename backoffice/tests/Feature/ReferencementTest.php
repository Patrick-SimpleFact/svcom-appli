<?php

use Database\Seeders\GenresSeeder;
use Database\Seeders\ParametresSeeder;
use Illuminate\Mail\Markdown;

/** SEO 1 : le site public a une seule adresse (domaine principal), un robots.txt et un plan du site ; le sous-domaine du BO est fermé. */
beforeEach(function () {
    $this->seed([ParametresSeeder::class, GenresSeeder::class]);
    config(['app.url_site' => 'https://spectacles-vivants.com']);
});

it('ouvre le domaine principal aux moteurs, sauf les pages techniques, et y annonce le plan du site', function () {
    $this->get('https://spectacles-vivants.com/robots.txt')
        ->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee("User-agent: *\nDisallow: /accueil/\nDisallow: /beta/\nDisallow: /espace-salle/", false)
        ->assertSee('Sitemap: https://spectacles-vivants.com/sitemap.xml', false);
});

it('ferme le sous-domaine du back-office aux moteurs', function () {
    $this->get('https://spettacoli.spectacles-vivants.com/robots.txt')
        ->assertOk()->assertSee("User-agent: *\nDisallow: /", false)->assertDontSee('Sitemap');
});

it('liste l’accueil dans le plan du site', function () {
    $this->get('https://spectacles-vivants.com/sitemap.xml')
        ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>https://spectacles-vivants.com/</loc>', false);
});

it('redirige l’accueil et le plan du site du sous-domaine vers le domaine principal, paramètres gardés', function () {
    $this->get('https://spettacoli.spectacles-vivants.com/?ville=avignon')->assertStatus(301)->assertRedirect('https://spectacles-vivants.com/?ville=avignon');
    $this->get('https://spettacoli.spectacles-vivants.com/sitemap.xml')->assertStatus(301)->assertRedirect('https://spectacles-vivants.com/sitemap.xml');
});

it('donne à l’accueil son adresse canonique, son image de partage et ses données structurées', function () {
    $page = $this->get('https://spectacles-vivants.com/')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://spectacles-vivants.com/">', false)
        ->assertSee('<meta property="og:image" content="https://spectacles-vivants.com/images/partage.png">', false)
        ->getContent();

    // Le bloc JSON-LD doit être du JSON valide (Blade ne doit pas toucher à « @context »).
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $page, $bloc);
    $donnees = json_decode($bloc[1] ?? '', true);
    expect($donnees['@context'] ?? null)->toBe('https://schema.org')
        ->and(collect($donnees['@graph'])->firstWhere('@type', 'Organization')['logo'])->toBe('https://spectacles-vivants.com/images/logo-512.png');

    expect(file_exists(public_path('images/partage.png')))->toBeTrue()
        ->and(file_exists(public_path('images/logo-512.png')))->toBeTrue()
        ->and(filesize(public_path('favicon.ico')))->toBeGreaterThan(0);
});

it('donne au site les icônes et le logo de la marque, et aux e-mails le logo du site principal', function () {
    $this->get('https://spectacles-vivants.com/')->assertOk()
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertSee('<img src="/images/logo.svg" alt="Spettacoli"', false);

    foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png',
        'maskable-icon-512x512.png', 'images/logo.svg', 'images/logo-sombre.svg', 'images/logo-email.png'] as $fichier) {
        expect(file_exists(public_path($fichier)))->toBeTrue("{$fichier} manque");
    }
    $manifeste = json_decode(file_get_contents(public_path('site.webmanifest')), true);
    foreach ($manifeste['icons'] as $icone) {
        expect(file_exists(public_path(ltrim($icone['src'], '/'))))->toBeTrue();
    }

    config(['app.url_site' => 'https://spectacles-vivants.com']);
    $html = (string) app(Markdown::class)->render('mail.code-connexion', ['code' => '123456', 'minutes' => 10]);
    expect($html)->toContain('src="https://spectacles-vivants.com/images/logo-email.png"');
});

it('sert l’espace salle seulement sur le sous-domaine : pas de page de connexion sur le domaine principal', function () {
    config(['app.url' => 'https://spettacoli.spectacles-vivants.com']);

    $this->get('https://spectacles-vivants.com/espace-salle/connexion')->assertStatus(301)->assertRedirect('https://spettacoli.spectacles-vivants.com/espace-salle/connexion');
    $this->get('https://spectacles-vivants.com/espace-salle/demande?source=accueil')->assertStatus(301)->assertRedirect('https://spettacoli.spectacles-vivants.com/espace-salle/demande?source=accueil');
    $this->get('https://spettacoli.spectacles-vivants.com/espace-salle/connexion')->assertOk();

    $this->get('https://spectacles-vivants.com/')->assertOk()
        ->assertSee('href="https://spettacoli.spectacles-vivants.com/espace-salle/connexion"', false)
        ->assertDontSee('href="https://spectacles-vivants.com/espace-salle/', false);
});
