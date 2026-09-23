<?php

namespace Spokospace\OpsNotify\Filament;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use UnitEnum;

/**
 * Register in a panel provider:
 *
 *     ->plugin(OpsNotifyPlugin::make()
 *         ->navigationGroup('System')
 *         ->authorize(fn () => auth()->user()?->is_admin))
 */
class OpsNotifyPlugin implements Plugin
{
    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected ?Closure $authorizeUsing = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'ops-notify';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([OpsNotifyPage::class]);
    }

    public function boot(Panel $panel): void {}

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function navigationIcon(string|BackedEnum|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return $this->navigationIcon;
    }

    /** Who may open the page. Without it, every user who can access the panel can. */
    public function authorize(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return $this->authorizeUsing === null || (bool) app()->call($this->authorizeUsing);
    }
}
