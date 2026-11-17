<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Artisan;

Artisan::command('service:info', function () {
    $this->info(config('app.name').' — '.config('app.url'));
})->purpose('Show service name and URL');

Artisan::command('memberships:plans', function () {
    $this->table(['Código', 'Plan', 'Créditos', 'Días'], Plan::orderBy('tier')->get(['code', 'name', 'price_credits', 'duration_days'])->toArray());
})->purpose('List the membership plans');
