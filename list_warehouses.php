<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

foreach(App\Models\Warehouse::all() as $w) {
    echo $w->id . ':' . $w->name . PHP_EOL;
}
