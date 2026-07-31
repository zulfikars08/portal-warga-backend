<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/roles-permissions', 'app', ['page' => 'roles', 'title' => 'Role & Izin']);
Route::view('/settings', 'app', ['page' => 'settings', 'title' => 'Pengaturan']);
