@props(['user', 'size' => ''])
@php
    $imageUrl = $user?->profile_image
        ? route('profile.avatar', ['v' => hash('sha256', $user->profile_image)])
        : null;
    $initial = mb_strtoupper(mb_substr(trim($user?->name ?? 'BAYANI'), 0, 1));
@endphp
@if($imageUrl)
    <img {{ $attributes->merge(['class' => 'avatar '.$size]) }} src="{{ $imageUrl }}" alt="{{ $user->name }} avatar" loading="eager" referrerpolicy="same-origin">
@else
    <span {{ $attributes->merge(['class' => 'avatar '.$size]) }} aria-label="{{ $user?->name ?? 'BAYANI' }}">{{ $initial }}</span>
@endif
