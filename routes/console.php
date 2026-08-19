<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('stock:sync-verwimp')
    ->dailyAt('06:00')
    ->withoutOverlapping(120);

Schedule::command('stock:sync-hoop-fietsen')
    ->dailyAt('07:00')
    ->withoutOverlapping(120);
