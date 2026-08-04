{{-- 'slot' is reserved by Blade for component content, hence ghostSlot. --}}
@props(['ghostSlot' => 0, 'size' => 'h-6 w-6'])

@php
    $ghosts = config('sprites.ghosts');
    $ghost = $ghosts[max(0, (int) $ghostSlot) % count($ghosts)];
@endphp

{{-- Slots past the pack's four painted ghosts are recoloured, matching how
     the canvas client draws them. --}}
<img src="{{ $ghost['frames'][0] }}"
     alt="{{ $ghost['label'] ?? 'Ghost' }} ghost"
     @style(['image-rendering: pixelated', 'filter: hue-rotate('.($ghost['hue'] ?? 0).'deg)' => isset($ghost['hue'])])
     {{ $attributes->merge(['class' => $size]) }}>
