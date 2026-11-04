{{--
    Wisselt tussen het volledige en het eenvoudige menu (App\Services\AdminMenu).
    Een gewoon formulier, zodat het ook werkt voordat Vue geladen is. Eigen CSS:
    Tailwind compileert alleen klassen die bij de laatste build in de bron stonden.
--}}
<form method="POST" action="{{ route('admin.menu-mode.update') }}" class="ms-switch" role="group" aria-label="Menuweergave">
    @csrf

    @foreach ([\App\Services\AdminMenu::SIMPLE => 'Eenvoudig', \App\Services\AdminMenu::FULL => 'Volledig'] as $mode => $label)
        <button
            type="submit"
            name="mode"
            value="{{ $mode }}"
            aria-pressed="{{ ($menuMode ?? \App\Services\AdminMenu::FULL) === $mode ? 'true' : 'false' }}"
        >{{ $label }}</button>
    @endforeach
</form>

{{-- In de scripts-stack (buiten #app): Vue gooit <style>-tags binnen #app weg. --}}
@pushOnce('scripts')
    <style>
        .ms-switch { display: flex; gap: 2px; margin: 14px 16px 6px; padding: 3px; border-radius: 8px; background: #f3f4f6; }
        .dark .ms-switch { background: #111827; }
        .ms-switch button { flex: 1; padding: 5px 8px; border: 0; border-radius: 6px; background: transparent; color: #6b7280; font-size: 13px; font-weight: 600; cursor: pointer; }
        .ms-switch button[aria-pressed=true] { background: #fff; color: #ff6700; box-shadow: 0 1px 2px rgba(0, 0, 0, .1); cursor: default; }
        .dark .ms-switch button { color: #9ca3af; }
        .dark .ms-switch button[aria-pressed=true] { background: #1f2937; color: #ff8a3d; }
        .sidebar-collapsed .ms-switch { display: none; }
    </style>
@endPushOnce
