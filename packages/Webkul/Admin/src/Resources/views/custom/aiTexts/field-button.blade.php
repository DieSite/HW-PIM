{{--
    Schrijf één tekstveld met AI. Staat naast de kopknop die alle drie de
    teksten in één call opvraagt: dat is goedkoper voor een nieuw product,
    dit is goedkoper als je er maar één wilt bijwerken.
--}}
<div class="mt-2">
    <button
        type="button"
        class="secondary-button flex items-center gap-1 text-xs"
        onclick="generateAiTexts(this, ['{{ $fieldCode }}'])"
    >
        <span class="icon-magic-wand text-sm"></span>
        Schrijf met AI
    </button>
</div>
