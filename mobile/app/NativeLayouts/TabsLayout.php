<?php

namespace App\NativeLayouts;

use App\Icons\Android;
use App\Icons\Ios;
use App\Services\Tabs\TabPreferences;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Bottom tabs for the main areas this device has turned on, with Settings in the top bar.
 */
class TabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle())
            ->action(NavAction::make('settings')->icon(ios: Ios::Gearshape, android: Android::Settings)->a11yLabel('Settings')->url('/settings'));
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        $tabBar = TabBar::make()->labelVisibility('labeled');

        foreach (app(TabPreferences::class)->enabled() as $tab) {
            $tabBar->add($tab->toTab());
        }

        return $tabBar;
    }
}
