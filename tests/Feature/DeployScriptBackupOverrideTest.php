<?php

declare(strict_types=1);

it('can skip only the pre-deploy dump while still running ingestion migrations', function (): void {
    $source = file_get_contents(base_path('deploy.sh'));

    expect($source)
        ->toContain('if [ "${SKIP_DB_BACKUP:-0}" = "1" ]; then')
        ->toContain('DB backup: skipped (SKIP_DB_BACKUP=1)')
        ->toMatch('/if \[ "\$\{SKIP_DB_BACKUP:-0\}" = "1" \]; then.*?else.*?mysqldump.*?fi\s+su - \$KRAITE_USER -c ".*?php artisan migrate --force --no-interaction"/s');
});
