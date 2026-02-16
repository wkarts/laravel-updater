<div class="card settings-subcard">
    <h3>Upload de backups para nuvem</h3>

    @php($provider = $backupUpload['provider'] ?? 'none')
    @php($dropbox = $backupUpload['dropbox'] ?? [])
    @php($google = $backupUpload['google_drive'] ?? [])
    @php($s3 = $backupUpload['s3'] ?? [])

    <form method="POST" action="{{ route('updater.settings.backup-upload.save') }}" class="form-grid" style="margin-top:10px;">
        @csrf

        <div>
            <label for="provider">Provedor</label>
            <select id="provider" name="provider">
                <option value="none" @selected($provider === 'none')>Desabilitado</option>
                <option value="dropbox" @selected($provider === 'dropbox')>Dropbox</option>
                <option value="google-drive" @selected($provider === 'google-drive')>Google Drive</option>
                <option value="s3" @selected($provider === 's3')>S3</option>
                <option value="minio" @selected($provider === 'minio')>MinIO</option>
            </select>
        </div>

        <div>
            <label for="prefix">Pasta remota</label>
            <input id="prefix" name="prefix" type="text" value="{{ $backupUpload['prefix'] ?? 'updater/backups' }}" placeholder="updater/backups">
        </div>

        <label class="settings-toggle" for="auto_upload">
            <input type="checkbox" id="auto_upload" name="auto_upload" value="1" @checked((bool) ($backupUpload['auto_upload'] ?? false))>
            <span>Upload automático após backup (não bloqueia finalização local)</span>
        </label>

        <div class="card" style="margin-top:8px;">
            <h4 style="margin:0 0 8px;">Credenciais Dropbox</h4>
            <label for="dropbox_access_token">Access Token</label>
            <input id="dropbox_access_token" name="dropbox_access_token" type="password" value="{{ $dropbox['access_token'] ?? '' }}" autocomplete="off">
        </div>

        <div class="card" style="margin-top:8px;">
            <h4 style="margin:0 0 8px;">Credenciais Google Drive</h4>
            <div class="form-grid">
                <div>
                    <label for="google_client_id">Client ID</label>
                    <input id="google_client_id" name="google_client_id" type="text" value="{{ $google['client_id'] ?? '' }}" autocomplete="off">
                </div>
                <div>
                    <label for="google_client_secret">Client Secret</label>
                    <input id="google_client_secret" name="google_client_secret" type="password" value="{{ $google['client_secret'] ?? '' }}" autocomplete="off">
                </div>
                <div>
                    <label for="google_refresh_token">Refresh Token</label>
                    <input id="google_refresh_token" name="google_refresh_token" type="password" value="{{ $google['refresh_token'] ?? '' }}" autocomplete="off">
                </div>
                <div>
                    <label for="google_folder_id">Folder ID (opcional)</label>
                    <input id="google_folder_id" name="google_folder_id" type="text" value="{{ $google['folder_id'] ?? '' }}" autocomplete="off">
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:8px;">
            <h4 style="margin:0 0 8px;">Credenciais S3 / MinIO</h4>
            <div class="form-grid">
                <div>
                    <label for="s3_endpoint">Endpoint</label>
                    <input id="s3_endpoint" name="s3_endpoint" type="text" value="{{ $s3['endpoint'] ?? '' }}" placeholder="https://s3.amazonaws.com ou https://minio.exemplo.com">
                </div>
                <div>
                    <label for="s3_region">Região</label>
                    <input id="s3_region" name="s3_region" type="text" value="{{ $s3['region'] ?? 'us-east-1' }}">
                </div>
                <div>
                    <label for="s3_bucket">Bucket</label>
                    <input id="s3_bucket" name="s3_bucket" type="text" value="{{ $s3['bucket'] ?? '' }}">
                </div>
                <div>
                    <label for="s3_access_key">Access Key</label>
                    <input id="s3_access_key" name="s3_access_key" type="text" value="{{ $s3['access_key'] ?? '' }}" autocomplete="off">
                </div>
                <div>
                    <label for="s3_secret_key">Secret Key</label>
                    <input id="s3_secret_key" name="s3_secret_key" type="password" value="{{ $s3['secret_key'] ?? '' }}" autocomplete="off">
                </div>
                <label class="settings-toggle" for="s3_path_style">
                    <input type="checkbox" id="s3_path_style" name="s3_path_style" value="1" @checked((bool) ($s3['path_style'] ?? true))>
                    <span>Forçar path-style (recomendado para MinIO)</span>
                </label>
            </div>
        </div>

        <div class="form-inline">
            <button class="btn btn-primary" type="submit">Salvar upload em nuvem</button>
        </div>
    </form>
</div>
