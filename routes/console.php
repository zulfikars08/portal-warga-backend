<?php

use App\Services\MonthlyBillService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\{Artisan,Schedule};

Artisan::command('inspire', function () {$this->comment(Inspiring::quote());});

Artisan::command('bills:generate {period?}', function (MonthlyBillService $service) {
    $result = $service->generate($this->argument('period') ?: now()->startOfMonth()->toDateString());
    $this->info("Created {$result['created']} bills for {$result['period']}.");
})->purpose('Generate idempotent monthly bills');

Schedule::command('bills:generate')->monthlyOn(1,'00:05')->withoutOverlapping();
