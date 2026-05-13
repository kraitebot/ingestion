<?php

declare(strict_types=1);

/**
 * Pins the credential-safety contract for the pre-recovery DB snapshot.
 *
 * Pre-fix, `mysqldump -p$password` exposed the DB password through the
 * process listing (`ps auxf`, /proc/<pid>/cmdline) for the duration of
 * the dump. On shared hosts this was a real exposure — anyone with shell
 * access could read the credential.
 *
 * Post-fix, the password flows via `MYSQL_PWD` env var. The mysqldump
 * binary picks it up from the child process environment without ever
 * appearing on the command line.
 */
it('snapshotDatabase passes the DB password via MYSQL_PWD, not the -p<pass> command-line flag', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/RecoverPositionsCommand.php')
    );

    expect($source)->toContain("putenv('MYSQL_PWD=")
        ->and($source)->not->toMatch('/-p%s.*mysqldump|mysqldump.*-p%s/s');
});
