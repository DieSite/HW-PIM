-- ---------------------------------------------------------------------------
-- Voegt de ontbrekende "Rond Maatwerk" optie toe aan het `maatgroep` attribuut.
--
-- Achtergrond: 1495 producten hebben `values.common.maatgroep = "Rond Maatwerk"`,
-- maar er bestaat geen bijbehorende rij in `attribute_options`. Daardoor toont
-- het select-veld in de PIM-admin leeg. De productwaarden zijn al correct en
-- worden hier NIET aangeraakt -- na dit script matchen ze vanzelf.
--
-- Idempotent: twee keer draaien voegt niets dubbel toe.
-- ---------------------------------------------------------------------------

START TRANSACTION;

-- 1. De optie zelf. attribute_id wordt opgezocht via de code, niet hardcoded,
--    zodat dit script ook op productie klopt.
INSERT INTO `attribute_options` (`attribute_id`, `code`, `sort_order`, `swatch_value`)
SELECT
    a.`id`,
    'Rond Maatwerk',
    COALESCE((SELECT MAX(o.`sort_order`) FROM `attribute_options` o WHERE o.`attribute_id` = a.`id`), 0) + 1,
    NULL
FROM `attributes` a
WHERE a.`code` = 'maatgroep'
  AND NOT EXISTS (
      SELECT 1
      FROM `attribute_options` o
      WHERE o.`attribute_id` = a.`id`
        AND o.`code` = 'Rond Maatwerk'
  );

-- 2. Vertalingen voor de twee actieve locales (en_US, nl_NL).
INSERT INTO `attribute_option_translations` (`attribute_option_id`, `locale`, `label`)
SELECT o.`id`, l.`locale`, 'Rond Maatwerk'
FROM `attribute_options` o
JOIN `attributes` a
  ON a.`id` = o.`attribute_id`
JOIN (SELECT 'en_US' AS `locale` UNION ALL SELECT 'nl_NL') l
WHERE a.`code` = 'maatgroep'
  AND o.`code` = 'Rond Maatwerk'
  AND NOT EXISTS (
      SELECT 1
      FROM `attribute_option_translations` t
      WHERE t.`attribute_option_id` = o.`id`
        AND t.`locale` = l.`locale`
  );

COMMIT;

-- ---------------------------------------------------------------------------
-- Controle (mag los gedraaid worden):
--
--   SELECT o.id, o.code, o.sort_order, t.locale, t.label
--   FROM attribute_options o
--   JOIN attributes a ON a.id = o.attribute_id
--   LEFT JOIN attribute_option_translations t ON t.attribute_option_id = o.id
--   WHERE a.code = 'maatgroep' AND o.code = 'Rond Maatwerk';
--
-- Verwacht: 1 optie met 2 translation-rijen (en_US + nl_NL).
-- ---------------------------------------------------------------------------
