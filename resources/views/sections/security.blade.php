@extends('laravel-updater::layout')
@section('page_title', 'Security')
@section('content')<div class="card"><h3>Segurança</h3><p>2FA, sessões e trilha de auditoria disponíveis no perfil e no banco SQLite interno.</p><a href="{{ route('updater.profile') }}">Ir para perfil/2FA</a></div>@endsection
