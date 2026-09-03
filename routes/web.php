<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Media module owns this route — see app/Modules/Media/routes-web.php for
// why it exists here, unprefixed, instead of the public/storage symlink.
require __DIR__.'/../app/Modules/Media/routes-web.php';
