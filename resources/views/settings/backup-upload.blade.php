<div class="card settings-subcard">
    <h3>Upload de backups para nuvem</h3>
    <p class="muted">Configuração feita na UI (sem depender de ENV). Use um disk já configurado no Laravel para Dropbox, Google Drive, S3 ou MinIO.</p>

    <form method="POST" action="{{ route('updater.settings.backup-upload.save') }}" class="form-grid" style="margin-top:10px;">
        @csrf
        <div>
            <label for="provider">Provedor</label>
            <select id="provider" name="provider">
                @php($provider = $backupUpload['provider'] ?? 'none')
                <option value="none" @selected($provider === 'none')>Desabilitado</option>
                <option value="dropbox" @selected($provider === 'dropbox')>Dropbox</option>
                <option value="google-drive" @selected($provider === 'google-drive')>Google Drive</option>
                <option value="s3" @selected($provider === 's3')>S3</option>
                <option value="minio" @selected($provider === 'minio')>MinIO</option>
            </select>
        </div>

        <div>
            <label for="disk">Disk Laravel</label>
            <input id="disk" name="disk" type="text" value="{{ $backupUpload['disk'] ?? '' }}" placeholder="ex.: dropbox, google, s3, minio">
        </div>

        <div>
            <label for="prefix">Pasta remota</label>
            <input id="prefix" name="prefix" type="text" value="{{ $backupUpload['prefix'] ?? 'updater/backups' }}" placeholder="updater/backups">
        </div>

        <label class="settings-toggle" for="auto_upload">
            <input type="checkbox" id="auto_upload" name="auto_upload" value="1" @checked((bool) ($backupUpload['auto_upload'] ?? false))>
            <span>Upload automático após backup (não bloqueia conclusão em caso de falha de upload)</span>
        </label>

        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Salvar upload em nuvem</button>
        </div>
    </form>
</div>
