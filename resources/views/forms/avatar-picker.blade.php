{{--
    Bot avatar picker: round preset thumbnails between a "keep current" and a "custom file" tile.
    Plain CSS on purpose: the app's Tailwind build does not scan package views. The selected ring
    goes through :class, not :style — Alpine replaces the whole style attribute for a string.
--}}
@php
    $tiles = [
        ['value' => null, 'label' => $keepLabel, 'icon' => '↺'],
        ...collect($avatars)->map(fn (string $url, string $key): array => ['value' => $key, 'label' => null, 'image' => $url])->values()->all(),
        ['value' => $customValue, 'label' => $customLabel, 'icon' => '⤒'],
    ];
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        /* Filament palette (gray = zinc by default). Contrast on the modal background (white / gray-900):
           labels 10:1+, icons, borders, focus and selection 3:1+ (WCAG 1.4.3 / 1.4.11). */
        .ops-avatar-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 12px; }
        .ops-avatar-tile { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 0; border: 0; background: none; color: var(--gray-700); cursor: pointer; font-size: 12px; line-height: 1.2; text-align: center; outline: none; }
        .ops-avatar-ring { box-sizing: border-box; width: 100%; aspect-ratio: 1; border-radius: 9999px; display: grid; place-items: center; overflow: hidden; transition: box-shadow .15s, border-color .15s; }
        .ops-avatar-ring img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ops-avatar-ring.is-icon { border: 2px dashed var(--gray-500); font-size: 24px; color: var(--gray-600); }
        .ops-avatar-tile:hover .ops-avatar-ring { box-shadow: 0 0 0 2px var(--gray-400); }
        .ops-avatar-tile:hover .ops-avatar-ring.is-icon { border-style: solid; box-shadow: none; }
        .ops-avatar-tile:focus-visible .ops-avatar-ring { box-shadow: 0 0 0 2px var(--gray-600); }
        .ops-avatar-ring.is-selected, .ops-avatar-tile:hover .ops-avatar-ring.is-selected { box-shadow: 0 0 0 3px var(--primary-600); }
        .ops-avatar-ring.is-icon.is-selected { border-style: solid; border-color: var(--primary-600); box-shadow: 0 0 0 1px var(--primary-600); color: var(--primary-600); }

        .dark .ops-avatar-tile { color: var(--gray-300); }
        .dark .ops-avatar-ring.is-icon { border-color: var(--gray-400); color: var(--gray-300); }
        .dark .ops-avatar-tile:hover .ops-avatar-ring { box-shadow: 0 0 0 2px var(--gray-500); }
        .dark .ops-avatar-tile:hover .ops-avatar-ring.is-icon { box-shadow: none; }
        .dark .ops-avatar-tile:focus-visible .ops-avatar-ring { box-shadow: 0 0 0 2px var(--gray-300); }
        .dark .ops-avatar-ring.is-selected, .dark .ops-avatar-tile:hover .ops-avatar-ring.is-selected { box-shadow: 0 0 0 3px var(--primary-400); }
        .dark .ops-avatar-ring.is-icon.is-selected { border-color: var(--primary-400); box-shadow: 0 0 0 1px var(--primary-400); color: var(--primary-400); }
    </style>

    <div x-data="{ state: $wire.$entangle(@js($getStatePath())) }" class="ops-avatar-grid">
        @foreach ($tiles as $tile)
            <button
                type="button"
                x-on:click="state = @js($tile['value'])"
                title="{{ $tile['label'] ?? $tile['value'] }}"
                class="ops-avatar-tile"
            >
                <span
                    @class(['ops-avatar-ring', 'is-icon' => isset($tile['icon'])])
                    x-bind:class="{ 'is-selected': state === @js($tile['value']) }"
                >
                    @isset($tile['image'])
                        <img src="{{ $tile['image'] }}" alt="{{ $tile['value'] }}" loading="lazy">
                    @else
                        <span aria-hidden="true">{{ $tile['icon'] }}</span>
                    @endisset
                </span>

                @if ($tile['label'])
                    <span>{{ $tile['label'] }}</span>
                @endif
            </button>
        @endforeach
    </div>
</x-dynamic-component>
