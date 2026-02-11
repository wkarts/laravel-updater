@extends('laravel-updater::layout')
@section('title', 'Novo perfil')
@section('page_title', 'Novo perfil')
@section('breadcrumbs', 'Perfis / Novo')

@section('content')
<div class="card">
    <h3>Cadastrar perfil</h3>
    <form method="POST" action="{{ route('updater.profiles.store') }}" class="form-grid" style="margin-top:10px;">
        @csrf
        @include('laravel-updater::profiles.form')
        <button class="btn btn-primary" type="submit">Salvar</button>
    </form>
</div>
@endsection
