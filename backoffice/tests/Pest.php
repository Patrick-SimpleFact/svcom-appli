<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Faux Ollama pour toutes les files : $repondre(élément envoyé, modèle) donne le résultat (sans id), ou null pour ne rien répondre.
 */
function ollamaRepond(Closure $repondre): void
{
    Http::fake([
        'ollama.com/api/balance' => Http::response(['included' => [
            'session' => ['remaining_percent' => 90, 'resets_at' => now()->addHours(2)->toIso8601String()],
            'weekly' => ['remaining_percent' => 90, 'resets_at' => now()->addDays(3)->toIso8601String()],
        ], 'purchased' => ['balance_usd' => 0]]),
        'localhost:11434/api/chat' => function (Request $requete) use ($repondre) {
            $elements = collect(json_decode($requete['messages'][1]['content'], true))->first();
            $resultats = collect($elements)
                ->map(fn (array $e) => ($r = $repondre($e, $requete['model'])) === null ? null : ['id' => $e['id'], 'justification' => 'Indice décisif.', ...$r])
                ->filter()->values()->all();

            return Http::response(['message' => ['content' => json_encode(['resultats' => $resultats])], 'prompt_eval_count' => 500, 'eval_count' => 80]);
        },
    ]);
}
