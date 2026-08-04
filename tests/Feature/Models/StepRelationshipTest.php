<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Indicator;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\User;
use StepDispatcher\Models\Step;

it('resolves model step relationships through the step dispatcher model', function (string $modelClass): void {
    $model = new $modelClass;

    expect($model->steps()->getRelated())->toBeInstanceOf(Step::class);
})->with([
    Account::class,
    ApiSystem::class,
    ExchangeSymbol::class,
    Indicator::class,
    Order::class,
    Position::class,
    Symbol::class,
    User::class,
]);
