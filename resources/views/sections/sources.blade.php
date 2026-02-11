@extends('laravel-updater::layout')
@section('page_title', 'Sources')
@section('content')
<div class="grid"><div class="card"><h3>Nova source</h3><form method="POST" action="{{ route('updater.sources.save') }}">@csrf
<input name="name" placeholder="Nome" required>
<select name="type"><option>github</option><option>gitlab</option><option>bitbucket</option><option>git</option><option>zip</option></select>
<input name="repo_url" placeholder="URL" required>
<input name="branch" placeholder="Branch" value="main">
<select name="auth_mode"><option>none</option><option>token</option><option>ssh</option></select>
<input name="token_encrypted" placeholder="Token (mascarar na UI)">
<label><input type="checkbox" name="active" value="1"> Ativa</label>
<button type="submit">Salvar</button></form>
<form method="POST" action="{{ route('updater.sources.test') }}" style="margin-top:8px">@csrf <button class="secondary">Testar conexão (simulado)</button></form>
</div><div class="card"><h3>Sources cadastradas</h3><table><thead><tr><th>Nome</th><th>Tipo</th><th>Branch</th><th>Ativa</th><th></th></tr></thead><tbody>@foreach($sources as $source)<tr><td>{{ $source['name'] }}</td><td>{{ $source['type'] }}</td><td>{{ $source['branch'] }}</td><td>{{ (int)$source['active']===1?'Sim':'Não' }}</td><td><form method="POST" action="{{ route('updater.sources.activate',$source['id']) }}">@csrf <button class="secondary">Ativar</button></form></td></tr>@endforeach</tbody></table></div></div>
@endsection
