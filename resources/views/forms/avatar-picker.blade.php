{{--
    Bot avatar picker: round preset thumbnails between a "keep current" and a "custom file" tile.
    Inline styles on purpose: the app's Tailwind build does not scan package views.
--}}
@php
    $tiles = [
        ['value' => null, 'label' => $keepLabel, 'icon' => '↺'],
        ...collect($avatars)->map(fn (string $url, string $key): array => ['value' => $key, 'label' => null, 'image' => $url])->values()->all(),
        ['value' => $customValue, 'label' => $customLabel, 'icon' => '⤒'],
    ];
    $ring = 'width: 100%; aspect-ratio: 1; border-radius: 9999px; display: grid; place-items: center; overflow: hidden; transition: box-shadow .15s;';
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{ state: $wire.$entangle(@js($getStatePath())) }"
        style="display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: 12px;"
    >
        @foreach ($tiles as $tile)
            <button
                type="button"
                x-on:click="state = @js($tile['value'])"
                title="{{ $tile['label'] ?? $tile['value'] }}"
                style="display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 0; border: 0; background: none; cursor: pointer; font-size: 12px; line-height: 1.2; text-align: center;"
            >
                <span
                    style="{{ $ring }} {{ isset($tile['icon']) ? 'border: 2px dashed rgba(127, 127, 127, .45);' : '' }}"
                    x-bind:style="state === @js($tile['value']) ? 'box-shadow: 0 0 0 3px var(--primary-500)' : ''"
                >
                    @isset($tile['image'])
                        <img src="{{ $tile['image'] }}" alt="{{ $tile['value'] }}" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                    @else
                        <span style="font-size: 22px; opacity: .7;">{{ $tile['icon'] }}</span>
                    @endisset
                </span>

                @if ($tile['label'])
                    <span style="opacity: .8;">{{ $tile['label'] }}</span>
                @endif
            </button>
        @endforeach
    </div>
</x-dynamic-component>
