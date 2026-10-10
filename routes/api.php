<?php

use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationMessageController;
use App\Http\Controllers\Api\V1\ConversationReplyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FoodRulesController;
use App\Http\Controllers\Api\V1\HouseholdNoteController;
use App\Http\Controllers\Api\V1\HouseholdSettingsController;
use App\Http\Controllers\Api\V1\MealApprovalController;
use App\Http\Controllers\Api\V1\MealController;
use App\Http\Controllers\Api\V1\MealPlanController;
use App\Http\Controllers\Api\V1\MealRegenerationController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecipeImportController;
use App\Http\Controllers\Api\V1\ShoppingListController;
use App\Http\Controllers\Api\V1\ShoppingListItemController;
use App\Http\Controllers\Api\V1\ShoppingListProposalController;
use App\Http\Controllers\Api\V1\SkylightSyncController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('tokens', [TokenController::class, 'store'])->middleware('throttle:api-login')->name('tokens.store');
    Route::delete('tokens/current', [TokenController::class, 'destroy'])->middleware('auth:sanctum')->name('tokens.destroy');
});

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('user', [UserController::class, 'show'])->name('user');

    Route::apiResource('conversations', ConversationController::class)->only(['index', 'store', 'show']);
    Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])->name('conversations.messages.store');
    Route::post('conversations/{conversation}/reply', ConversationReplyController::class)->name('conversations.reply');

    Route::apiResource('shopping-lists', ShoppingListController::class)->except(['destroy']);
    Route::apiResource('shopping-lists.items', ShoppingListItemController::class)->only(['store', 'update', 'destroy'])->scoped(['item' => 'id']);
    Route::post('shopping-lists/{shopping_list}/proposals', [ShoppingListProposalController::class, 'store'])->name('shopping-lists.proposals.store');

    Route::apiResource('meals', MealController::class);
    Route::post('meals/{meal}/approve', MealApprovalController::class)->name('meals.approve');
    Route::post('meals/{meal}/regenerate', MealRegenerationController::class)->name('meals.regenerate');
    Route::post('meal-plans', [MealPlanController::class, 'store'])->name('meal-plans.store');

    Route::post('recipes/import', RecipeImportController::class)->name('recipes.import');
    Route::apiResource('recipes', RecipeController::class);

    Route::apiResource('notes', HouseholdNoteController::class)->except(['show']);

    Route::get('food-rules', [FoodRulesController::class, 'show'])->name('food-rules.show');
    Route::put('food-rules', [FoodRulesController::class, 'update'])->name('food-rules.update');

    Route::get('household-settings', [HouseholdSettingsController::class, 'show'])->name('household-settings.show');
    Route::put('household-settings', [HouseholdSettingsController::class, 'update'])->name('household-settings.update');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('skylight/sync', SkylightSyncController::class)->name('skylight.sync');
});
