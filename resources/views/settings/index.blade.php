@extends('laravel-updater::layout')
@section('title', 'Configurações')
@section('page_title', 'Configurações')
@section('breadcrumbs', 'Configurações')

@section('content')
<div class="settings-stack">
    <section class="settings-top-grid">
        <div class="settings-block settings-branding">
            @include('laravel-updater::settings.branding', ['branding' => $branding])
        </div>

        <div class="settings-side-stack">
            @include('laravel-updater::settings.security')
            @include('laravel-updater::settings.tokens', ['tokens' => $tokens])
            @include('laravel-updater::settings.backup-upload', ['backupUpload' => $backupUpload])
            @include('laravel-updater::settings.sources', [
                'activeSource' => $activeSource,
                'sources' => $sources,
                'profiles' => $profiles,
            ])
        </div>
    </section>
</div>
@endsection
