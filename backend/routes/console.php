<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bsc:scan-deposits')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Detect & credit inbound USDT deposits');

Schedule::command('bsc:process-withdrawals')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Finish / broadcast pending withdrawals');

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Short commands are defined here. Note: keep a `php artisan schedule:work`
| (or a cron entry running `schedule:run` every minute) alive so deposits are
| detected and withdrawals broadcast automatically.
*/

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
