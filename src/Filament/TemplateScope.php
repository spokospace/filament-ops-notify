<?php

namespace Spokospace\OpsNotify\Filament;

use Filament\Schemas\Components\Utilities\Get;

/**
 * Where a template editor sits in the Settings form: the default look (the "*" template, in its
 * own fieldset) or an exception row (in the exceptions list). The editor's fields read the rest
 * of the form through relative state paths, and those differ by one level between the two.
 */
enum TemplateScope
{
    case DefaultLook;
    case Exception;

    /** The pattern the editor's template is stored under. */
    public function pattern(Get $get): string
    {
        return $this === self::DefaultLook ? '*' : trim((string) $get('pattern'));
    }

    /** The form root, relative to the editor's fields: fieldset → root, or row → list → root. */
    public function root(): string
    {
        return $this === self::DefaultLook ? '../' : '../../';
    }

    /** The exceptions list, relative to the editor's fields. */
    public function exceptions(): string
    {
        return $this === self::DefaultLook ? '../templates' : '../';
    }

    /**
     * What a change re-renders, relative to the editor's fields: the Templates section (fieldset
     * → section) or the exceptions list (row → list). Not the row itself: a key inside a repeater
     * row cannot be resolved while the form is being filled.
     */
    public function refreshTarget(): string
    {
        return '../';
    }
}
