<?php

namespace App\NativeComponents;

use App\Services\Api\Session;
use App\Services\Tabs\TabPreferences;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The root screen: sends the user to the first enabled tab when signed in, otherwise to SignIn.
 */
class Launch extends NativeComponent
{
    public function mount(Session $session, TabPreferences $tabs): void
    {
        $this->replace($session->isSignedIn() ? $tabs->homeRoute() : '/sign-in');
    }

    public function render(): View
    {
        return view('native.launch');
    }
}
