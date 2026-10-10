<?php

namespace App\NativeComponents\Settings;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use App\Services\Tabs\AppTab;
use App\Services\Tabs\TabPreferences;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Network;

/**
 * The signed-in account, the server address, Skylight sync and sign out.
 */
class SettingsScreen extends NativeComponent
{
    public string $userName = '';

    public string $userEmail = '';

    public string $serverUrl = '';

    public string $serverMessage = '';

    public bool $syncing = false;

    public string $syncMessage = '';

    public bool $syncFailed = false;

    public string $loadError = '';

    public string $tabsMessage = '';

    public function mount(Session $session, ApiClient $api): void
    {
        $this->serverUrl = $session->serverUrl();

        if (Network::status()?->connected === false) {
            $this->loadError = 'You are offline, so your account could not be loaded.';

            return;
        }

        try {
            $response = $api->get('/user');
        } catch (UnauthenticatedException) {
            $this->endSession($session);

            return;
        } catch (ConnectionException) {
            $this->loadError = 'Could not reach the server.';

            return;
        }

        if (! $response->successful()) {
            $this->loadError = 'Could not load your account.';

            return;
        }

        $this->userName = (string) ($response->json('data.name') ?? $response->json('name') ?? '');
        $this->userEmail = (string) ($response->json('data.email') ?? $response->json('email') ?? '');
    }

    public function navTitle(): string
    {
        return 'Settings';
    }

    public function saveServerUrl(Session $session): void
    {
        $session->setServerUrl($this->serverUrl);
        $this->serverUrl = $session->serverUrl();
        $this->serverMessage = 'Server address saved.';
    }

    /**
     * Turn a bottom tab on or off for this device; the last enabled tab stays on.
     */
    public function toggleTab(string $tab, TabPreferences $tabs): void
    {
        $appTab = AppTab::tryFrom($tab);

        if ($appTab === null) {
            return;
        }

        $this->tabsMessage = $tabs->set($appTab, ! $tabs->isEnabled($appTab))
            ? ''
            : 'At least one tab has to stay on.';
    }

    public function syncWithSkylight(ApiClient $api, Session $session): void
    {
        $this->syncMessage = '';
        $this->syncFailed = false;

        if (Network::status()?->connected === false) {
            $this->syncFailed = true;
            $this->syncMessage = 'You are offline. Try again when you are connected.';

            return;
        }

        $this->syncing = true;

        try {
            $response = $api->post('/skylight/sync');

            $this->syncFailed = ! $response->successful();
            $this->syncMessage = (string) ($response->json('message') ?: ($response->successful() ? 'Synced with Skylight.' : 'Skylight sync failed.'));
        } catch (UnauthenticatedException) {
            $this->endSession($session);
        } catch (ConnectionException) {
            $this->syncFailed = true;
            $this->syncMessage = 'Could not reach the server.';
        } finally {
            $this->syncing = false;
        }
    }

    public function signOut(ApiClient $api, Session $session): void
    {
        try {
            $api->delete('/tokens/current');
        } catch (UnauthenticatedException|ConnectionException) {
            // The token is already gone or the server is unreachable; sign out on this device regardless.
        }

        $this->endSession($session);
    }

    public function render(): View
    {
        $tabs = app(TabPreferences::class);

        return view('native.settings.settings-screen', [
            'tabOptions' => array_map(
                fn (AppTab $tab): array => ['key' => $tab->value, 'label' => $tab->label(), 'enabled' => $tabs->isEnabled($tab)],
                AppTab::cases(),
            ),
        ]);
    }

    protected function endSession(Session $session): void
    {
        $session->signOut();
        $this->replace('/sign-in');
    }
}
