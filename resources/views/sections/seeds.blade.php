@extends('laravel-updater::layout')
@section('page_title', 'Seeds')

@section('content')
<div class="card">
    <h3>Registro de seeds idempotentes</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Seeder</th><th>Checksum</th><th>Aplicado em</th><th>Revisão</th></tr></thead>
            <tbody>
            @forelse($seeds as $seed)
                <tr>
                    <td>{{ $seed['seeder_class'] }}</td>
                    <td>{{ $seed['checksum'] }}</td>
                    <td>{{ $seed['applied_at'] }}</td>
                    <td>{{ $seed['app_revision'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Nenhum seed registrado.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('updater.seeds.reapply') }}" class="form-grid" style="margin-top: 12px;">
        @csrf
        <label for="seeder_class">Reaplicar seeder (admin)</label>
        <input id="seeder_class" name="seeder_class" placeholder="Database\Seeders\MeuSeeder" required>
        <button class="btn btn-primary" type="submit">Reaplicar</button>
    </form>
</div>
@endsection
