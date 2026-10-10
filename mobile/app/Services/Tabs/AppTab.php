<?php

namespace App\Services\Tabs;

use App\Icons\Android;
use App\Icons\Ios;
use Native\Mobile\Edge\Layouts\Builders\Tab;

/**
 * The main areas of the app, in the order they appear in the bottom nav.
 */
enum AppTab: string
{
    case Today = 'today';
    case Chat = 'chat';
    case Meals = 'meals';
    case Lists = 'lists';
    case Recipes = 'recipes';
    case Household = 'household';

    public function route(): string
    {
        return '/'.$this->value;
    }

    public function label(): string
    {
        return $this->name;
    }

    public function iosIcon(): Ios
    {
        return match ($this) {
            self::Today => Ios::SunMax,
            self::Chat => Ios::BubbleLeftAndBubbleRight,
            self::Meals => Ios::ForkKnife,
            self::Lists => Ios::ListBullet,
            self::Recipes => Ios::BookClosed,
            self::Household => Ios::House,
        };
    }

    public function androidIcon(): Android
    {
        return match ($this) {
            self::Today => Android::WbSunny,
            self::Chat => Android::Chat,
            self::Meals => Android::Restaurant,
            self::Lists => Android::List,
            self::Recipes => Android::MenuBook,
            self::Household => Android::Home,
        };
    }

    public function toTab(): Tab
    {
        return Tab::link($this->label(), $this->route(), ios: $this->iosIcon(), android: $this->androidIcon());
    }
}
