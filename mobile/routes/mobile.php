<?php

use App\NativeComponents\Auth\SignIn;
use App\NativeComponents\Chat\ConversationIndex;
use App\NativeComponents\Chat\ConversationShow;
use App\NativeComponents\Household\HouseholdIndex;
use App\NativeComponents\Launch;
use App\NativeComponents\Lists\ShoppingListIndex;
use App\NativeComponents\Lists\ShoppingListShow;
use App\NativeComponents\Meals\MealIndex;
use App\NativeComponents\Meals\MealShow;
use App\NativeComponents\Recipes\RecipeForm;
use App\NativeComponents\Recipes\RecipeIndex;
use App\NativeComponents\Recipes\RecipeShow;
use App\NativeComponents\Settings\SettingsScreen;
use App\NativeComponents\Today\TodayScreen;
use App\NativeLayouts\TabsLayout;
use Illuminate\Support\Facades\Route;

Route::native('/', Launch::class);
Route::native('/sign-in', SignIn::class);

Route::nativeGroup(TabsLayout::class, function (): void {
    Route::native('/today', TodayScreen::class);
    Route::native('/chat', ConversationIndex::class);
    Route::native('/chat/new', ConversationShow::class);
    Route::native('/chat/{id}', ConversationShow::class);
    Route::native('/meals', MealIndex::class);
    Route::native('/meals/{id}', MealShow::class);
    Route::native('/lists', ShoppingListIndex::class);
    Route::native('/lists/{id}', ShoppingListShow::class);
    Route::native('/recipes', RecipeIndex::class);
    Route::native('/recipes/new', RecipeForm::class);
    Route::native('/recipes/{id}', RecipeShow::class);
    Route::native('/recipes/{id}/edit', RecipeForm::class);
    Route::native('/household', HouseholdIndex::class);
});

Route::native('/settings', SettingsScreen::class);
