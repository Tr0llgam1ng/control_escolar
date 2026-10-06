<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('service:info', function () {
    $this->info(config('app.name').' — '.config('app.url'));
})->purpose('Show service name and URL');
