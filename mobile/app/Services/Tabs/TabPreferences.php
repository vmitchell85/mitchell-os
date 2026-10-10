<?php

namespace App\Services\Tabs;

use Illuminate\Support\Facades\DB;

/**
 * Which bottom tabs this device shows, kept in the on-device database. Defaults to every tab, and a tab added in an update starts out on.
 */
class TabPreferences
{
    public const SETTING_KEY = 'tabs.enabled';

    /**
     * The tabs this device had a chance to turn on or off when its choice was saved. Tabs outside it are new.
     */
    public const KNOWN_KEY = 'tabs.known';

    /**
     * The tabs that existed before the known list was recorded.
     *
     * @var list<AppTab>
     */
    protected const ORIGINAL_TABS = [AppTab::Chat, AppTab::Meals, AppTab::Lists, AppTab::Recipes, AppTab::Household];

    /**
     * The enabled tabs, in the standard order.
     *
     * @return list<AppTab>
     */
    public function enabled(): array
    {
        $saved = $this->savedValues();

        if ($saved === null) {
            return AppTab::cases();
        }

        $known = $this->knownValues();

        $enabled = array_values(array_filter(
            AppTab::cases(),
            fn (AppTab $tab): bool => in_array($tab->value, $saved, true) || ! in_array($tab->value, $known, true),
        ));

        return $enabled === [] ? AppTab::cases() : $enabled;
    }

    public function isEnabled(AppTab $tab): bool
    {
        return in_array($tab, $this->enabled(), true);
    }

    /**
     * Turn a tab on or off. Returns false (and changes nothing) if that would leave no tabs.
     */
    public function set(AppTab $tab, bool $enabled): bool
    {
        $values = array_map(
            fn (AppTab $enabledTab): string => $enabledTab->value,
            array_filter($this->enabled(), fn (AppTab $enabledTab): bool => $enabledTab !== $tab),
        );

        if ($enabled) {
            $values[] = $tab->value;
        }

        if ($values === []) {
            return false;
        }

        $ordered = array_values(array_map(
            fn (AppTab $orderedTab): string => $orderedTab->value,
            array_filter(AppTab::cases(), fn (AppTab $orderedTab): bool => in_array($orderedTab->value, $values, true)),
        ));

        $known = array_map(fn (AppTab $knownTab): string => $knownTab->value, AppTab::cases());

        DB::table('device_settings')->upsert(
            [
                ['key' => self::SETTING_KEY, 'value' => json_encode($ordered)],
                ['key' => self::KNOWN_KEY, 'value' => json_encode($known)],
            ],
            ['key'],
            ['value'],
        );

        return true;
    }

    /**
     * The route the app opens to: the first enabled tab.
     */
    public function homeRoute(): string
    {
        return $this->enabled()[0]->route();
    }

    /**
     * @return list<string>|null
     */
    protected function savedValues(): ?array
    {
        return $this->storedList(self::SETTING_KEY);
    }

    /**
     * @return list<string>
     */
    protected function knownValues(): array
    {
        return $this->storedList(self::KNOWN_KEY)
            ?? array_map(fn (AppTab $tab): string => $tab->value, self::ORIGINAL_TABS);
    }

    /**
     * @return list<string>|null
     */
    protected function storedList(string $key): ?array
    {
        $value = DB::table('device_settings')->where('key', $key)->value('value');

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }
}
