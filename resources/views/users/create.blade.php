@extends('laravel-updater::layout')
@section('title', 'Novo usuário')
@section('page_title', 'Novo usuário')
@section('breadcrumbs', 'Usuários / Novo')

@section('content')
<div class="card">
    <h3>Cadastrar usuário</h3>
    @if(!empty($masterEmail))<p class="muted">Usuário master definido em <code>UPDATER_UI_MASTER_EMAIL</code>: <strong>{{ $masterEmail }}</strong>.</p>@endif
    <form method="POST" action="{{ route('updater.users.store') }}" class="form-grid" style="margin-top: 10px;">
        @csrf
        @include('laravel-updater::users.form')
        <button class="btn btn-primary" type="submit">Salvar</button>
    </form>
</div>
@endsection
