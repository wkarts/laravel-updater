@extends('laravel-updater::layout')
@section('title', 'Editar perfil')
@section('page_title', 'Editar perfil')
@section('breadcrumbs', 'Perfis / Editar')

@section('content')
<div class="card">
    <h3>Editar perfil de atualização</h3>
    <form method="POST" action="{{ route('updater.profiles.update', $profile['id']) }}" class="form-grid" style="margin-top:10px;">
        @csrf @method('PUT')
        @include('laravel-updater::profiles.form', ['profile' => $profile])
        <button class="btn btn-primary" type="submit">Salvar alterações</button>
    </form>
</div>
@endsection
