<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('chat/{conversation?}', 'pages::chat')->name('chat');

    Route::livewire('lists/{shoppingList?}', 'pages::lists')->name('lists');

    Route::livewire('meals', 'pages::meals')->name('meals');
});

require __DIR__.'/settings.php';
