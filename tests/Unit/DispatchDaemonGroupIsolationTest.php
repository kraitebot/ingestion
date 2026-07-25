<?php

declare(strict_types=1);

use Kraite\Core\Commands\DispatchDaemonCommand;

it('isolates a dispatcher exception to the group that threw it', function (): void {
    $reflection = new ReflectionClass(DispatchDaemonCommand::class);
    $sourceLines = file($reflection->getFileName());
    $method = $reflection->getMethod('dispatchGroups');
    $source = implode('', array_slice(
        $sourceLines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect($source)
        ->toMatch('/foreach\\s*\\(array_intersect\\(self::GROUPS, \\$groups\\) as \\$group\\)\\s*\\{\\s*try\\s*\\{/s')
        ->toContain('StepDispatcher::dispatch($group)')
        ->toContain('catch (Throwable $exception)');
});
