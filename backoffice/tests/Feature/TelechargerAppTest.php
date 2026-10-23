<?php

/** spectacles-vivants.com/app : l'adresse unique des affiches et QR codes (plan marketing de lancement). */
const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 27_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1';
const UA_ANDROID = 'Mozilla/5.0 (Linux; Android 16; Pixel 10) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36';
const UA_MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Safari/605.1.15';

beforeEach(function () {
    config(['app_mobile.app_store' => 'https://apps.apple.com/fr/app/spettacoli/id6822616128',
        'app_mobile.google_play' => 'https://play.google.com/store/apps/details?id=fr.spettacoli.app', 'app_mobile.app_store_pt' => null]);
});

it('envoie l’iPhone sur l’App Store et l’Android sur Google Play, sans mise en cache', function () {
    $this->get('/app', ['User-Agent' => UA_IPHONE])
        ->assertRedirect('https://apps.apple.com/fr/app/spettacoli/id6822616128')
        ->assertStatus(302)
        ->assertHeader('Vary', 'User-Agent');

    $this->get('/app', ['User-Agent' => UA_ANDROID])
        ->assertRedirect('https://play.google.com/store/apps/details?id=fr.spettacoli.app');
});

it('envoie un ordinateur sur la page d’accueil, qui montre les deux boutiques', function () {
    $this->get('/app', ['User-Agent' => UA_MAC])->assertRedirect(config('app.url_site').'/');
});

it('avant la sortie (boutiques non renseignées), envoie tout le monde sur l’accueil et la bêta', function () {
    config(['app_mobile.app_store' => null, 'app_mobile.google_play' => null]);

    $this->get('/app', ['User-Agent' => UA_IPHONE])->assertRedirect(config('app.url_site').'/');
    $this->get('/app', ['User-Agent' => UA_ANDROID])->assertRedirect(config('app.url_site').'/');
});

it('transmet la provenance ?c= à Google Play, et à l’App Store quand l’identifiant de fournisseur Apple est connu', function () {
    $this->get('/app?c=Affiche-Avignon', ['User-Agent' => UA_ANDROID])
        ->assertRedirect('https://play.google.com/store/apps/details?id=fr.spettacoli.app&referrer=utm_source%3Daffiche-avignon%26utm_medium%3Dlien-app');

    $this->get('/app?c=affiche-avignon', ['User-Agent' => UA_IPHONE])
        ->assertRedirect('https://apps.apple.com/fr/app/spettacoli/id6822616128');

    config(['app_mobile.app_store_pt' => '123456']);
    $this->get('/app?c=affiche-avignon', ['User-Agent' => UA_IPHONE])
        ->assertRedirect('https://apps.apple.com/fr/app/spettacoli/id6822616128?pt=123456&ct=affiche-avignon&mt=8');
});

it('ignore une provenance invalide', function () {
    $this->get('/app?c='.urlencode('<script>'), ['User-Agent' => UA_ANDROID])
        ->assertRedirect('https://play.google.com/store/apps/details?id=fr.spettacoli.app');
});
