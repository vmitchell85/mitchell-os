<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('chat/{conversation?}', 'pages::chat')->name('chat');

    Route::livewire('lists/{shoppingList?}', 'pages::lists')->name('lists');

    Route::livewire('meals', 'pages::meals')->name('meals');

    Route::livewire('recipes', 'pages::recipes.index')->name('recipes');
    Route::livewire('recipes/new', 'pages::recipes.form')->name('recipes.create');
    Route::livewire('recipes/{recipe}', 'pages::recipes.show')->name('recipes.show');
    Route::livewire('recipes/{recipe}/edit', 'pages::recipes.form')->name('recipes.edit');

    Route::livewire('household', 'pages::household')->name('household');
});

require __DIR__.'/settings.php';
