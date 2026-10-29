{{-- Version du B.O. en service, en haut du menu. Styles en ligne : pas de thème Filament compilé pour des classes Tailwind sur mesure. --}}
@php($version = config('app.version'))
<style>
    .version-bo { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; padding: .5rem .75rem; border-radius: .5rem; font-size: .875rem; background: var(--gray-100); color: var(--gray-500); }
    .version-bo strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; color: var(--gray-950); }
    .dark .version-bo { background: color-mix(in oklab, white 5%, transparent); color: var(--gray-400); }
    .dark .version-bo strong { color: white; }
</style>
<div class="version-bo" title="Version du back-office en service">
    <x-filament::icon icon="heroicon-m-tag" style="width: 1rem; height: 1rem; flex-shrink: 0;" />
    <span>Version</span>
    <strong>{{ $version ?: 'locale' }}</strong>
</div>
