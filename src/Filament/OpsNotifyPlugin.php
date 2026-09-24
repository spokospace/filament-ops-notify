<?php

namespace Spokospace\OpsNotify\Filament;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use UnitEnum;

/**
 * Register in a panel provider:
 *
 *     ->plugin(OpsNotifyPlugin::make()->navigationGroup('System'))
 *
 * and say who may use it, in AppServiceProvider::boot():
 *
 *     Gate::define('viewOpsNotify', fn (User $user) => $user->is_admin);
 *     Gate::define('manageOpsNotify', fn (User $user) => $user->is_admin); // optional
 *
 * or with ->authorize() / ->authorizeManagement() on the plugin.
 */
class OpsNotifyPlugin implements Plugin
{
    /** The page's name in the menu and title bar. A product name, so it is not translated. */
    public const DEFAULT_NAVIGATION_LABEL = 'Ops Notify';

    protected ?string $navigationLabel = null;

    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    /** Gate abilities checked when authorize() / authorizeManagement() are not used. */
    public const VIEW_ABILITY = 'viewOpsNotify';

    public const MANAGE_ABILITY = 'manageOpsNotify';

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $authorizeManagementUsing = null;

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

    /** Renames the page in the menu and title bar. Null restores the default. */
    public function navigationLabel(?string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): string
    {
        return filled($this->navigationLabel) ? $this->navigationLabel : self::DEFAULT_NAVIGATION_LABEL;
    }

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

    /**
     * Who may open the page (status and history). Without it, the viewOpsNotify gate decides;
     * without that gate, only the local environment gets in, as with Horizon and Telescope.
     */
    public function authorize(?Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * Who may change things: Settings, Bot profile, Send test, Resend. Without it, the
     * manageOpsNotify gate decides; without that gate, whoever may open the page.
     */
    public function authorizeManagement(?Closure $callback): static
    {
        $this->authorizeManagementUsing = $callback;

        return $this;
    }

    public function isAuthorized(): bool
    {
        return match (true) {
            $this->authorizeUsing !== null => (bool) app()->call($this->authorizeUsing),
            Gate::has(self::VIEW_ABILITY) => Gate::allows(self::VIEW_ABILITY),
            default => app()->environment('local'),
        };
    }

    public function canManage(): bool
    {
        return match (true) {
            $this->authorizeManagementUsing !== null => (bool) app()->call($this->authorizeManagementUsing),
            Gate::has(self::MANAGE_ABILITY) => Gate::allows(self::MANAGE_ABILITY),
            default => $this->isAuthorized(),
        };
    }
}
