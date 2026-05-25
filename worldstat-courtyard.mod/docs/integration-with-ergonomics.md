# Связь WorldStat Courtyard с базовой платформой и плагином Ergonomics

Пояснительная записка к итерации **«building ergonomics panel»**: какую роль играет каждый из трёх плагинов, как идут данные от OpenStreetMap до плашки эргономичности на странице здания, какие точки расширения добавляются в этой итерации.

## 1. Состав экосистемы

Три плагина связаны жёсткой зависимостью (декларируется в заголовках главных файлов через `Requires Plugins`):

- **World Statistics Platform** ([`world-statistics-platform.php`](../../world-statistics-platform/world-statistics-platform.php)) — базовая платформа: CPT стран, единый UI-каркас (`WorldStat_UI::stats_grid`, `WorldStat_UI::chart`, `WorldStat_UI::map`), реестр расширений (`WorldStat_Extensions::register/add_country_tab/add_map_layer`), общий шаблон single-страницы. Поднимается раньше всех (хук `worldstat_init`).
- **WorldStat Courtyard** ([`worldstat-courtyard.php`](../worldstat-courtyard.php)) — отвечает за OSM-данные: Overpass-запрос ([`class-wsc-overpass.php`](../includes/class-wsc-overpass.php)), палитры и виды озеленения полигонов ([`class-wsc-landcover.php`](../includes/class-wsc-landcover.php)), парсер ([`class-wsc-parser.php`](../includes/class-wsc-parser.php)), классификатор тегов ([`class-wsc-categories.php`](../includes/class-wsc-categories.php)), запись в кастомные таблицы `wsc_buildings/wsc_yards/wsc_pois/wsc_landuse` ([`class-wsc-writer.php`](../includes/class-wsc-writer.php)), расчёт буфера ([`class-wsc-buffer.php`](../includes/class-wsc-buffer.php)).
- **WorldStat Ergonomics** ([`worldstat-ergonomics.php`](../../worldstat-ergonomics/worldstat-ergonomics.php)) — отвечает за оценку: CPT `wsp_district`, `wsp_building`, `wsp_room`, `wsp_yard` ([`class-ergo-cpt.php`](../../worldstat-ergonomics/includes/class-ergo-cpt.php)), нормализация показателей в 0–100 ([`class-ergo-indicators.php`](../../worldstat-ergonomics/includes/class-ergo-indicators.php)), модель 6 измерений ([`class-ergo-model.php`](../../worldstat-ergonomics/includes/class-ergo-model.php)), сводный индекс E ([`class-ergo-calculator.php`](../../worldstat-ergonomics/includes/class-ergo-calculator.php)), шаблон страницы здания ([`templates/single-wsp_building.php`](../../worldstat-ergonomics/templates/single-wsp_building.php)).

Courtyard является «мостом» между платформой и Ergonomics: знает про обе стороны и связывает их через [`class-wsc-ergo-bridge.php`](../includes/class-wsc-ergo-bridge.php).

```mermaid
flowchart LR
    OSM[OpenStreetMap / Overpass] -->|scan| Parser[WSC_Parser]
    Parser --> Writer[WSC_Writer<br/>wsc_buildings/yards/pois/landuse]
    Writer -->|do_action wsc_buffer_recomputed| Bridge[WSC_Ergo_Bridge]
    Bridge -->|sync_yard_post| Yard[wsp_yard<br/>meta wsergo_raw_b_*]
    Bridge -->|sync_building_post NEW| Bld[wsp_building<br/>meta wsergo_raw_b_*]
    Yard --> Indicators[WSErgo_Indicators<br/>normalize 0..100]
    Bld --> Indicators
    Indicators --> Dims["6 dimensions: F/S/C/L/O/M"]
    Dims --> Calc[WSErgo_Calculator<br/>"E = sum w_i * x_i"]
    Calc -->|wsergo_index| Panel[Building page<br/>ergonomics panel]
    Manual[Admin metabox<br/>lux, dB, checklist] --> Bld
    Weights[Admin: weights + AHP] --> Calc
    Platform[WorldStat Platform<br/>UI/Extensions API] --> Panel
```

## 2. Поток данных в текущей итерации

1. Пользователь запускает скан города на странице «Придомовая территория».
2. `WSC_Overpass::query_bbox()` тянет OSM по bbox; в эту итерацию [запрос расширяется](../includes/class-wsc-overpass.php) на новые теги: `amenity={waste_basket|recycling|waste_disposal|bench|bicycle_parking|charging_station|drinking_water|fountain|fire_hydrant|parking}`, `highway=street_lamp`, `emergency=fire_hydrant`, `way[highway~footway|path|pedestrian]`, `way[sidewalk]`.
3. `WSC_Parser` нормализует элементы; здания/POI/landuse сохраняются в кастомные таблицы. В этой итерации в `pack_record` дополнительно сохраняется `sidewalk:width` (из тегов way/highway) и помечается тип footway — для расчёта суммарной длины тротуаров в буфере.
4. После сохранения здания и расчёта буфера срабатывает `do_action( 'wsc_buffer_recomputed', $building_id, $yard_id )` (см. [`class-wsc-writer.php`](../includes/class-wsc-writer.php)).
5. На этот хук подписан `WSC_Ergo_Bridge::on_buffer_recomputed()`. Если категория здания = `residential`:
   - Регистрирует определения индикаторов в опции `wsergo_indicator_definitions` (через `ensure_indicators_registered()` + новый класс `WSErgo_Building_Indicators`).
   - Создаёт/обновляет пост `wsp_yard` (как раньше) **и пост `wsp_building`** (новое в этой итерации) — оба линкуются на OSM-здание через мету `_wsc_building_id`.
   - Вызывает расширенный `write_poi_indicators()`, который пишет meta `wsergo_raw_b_*` И на yard, И на building. Парная мета `b_<id>_src=osm` помечает источник; ручной ввод (`manual`) имеет приоритет — OSM не перебивает.
6. После записи мет вызывается `WSErgo_Indicators::sync_dimension_meta_from_indicators()` (нормализация 0–100 по каждому измерению) и `WSErgo_Calculator::compute_and_store_index()` (сводный E).
7. На странице `wsp_building` (роутер платформы → фильтр `worldstat_single_template`) рендерится шаблон [`single-wsp_building.php`](../../worldstat-ergonomics/templates/single-wsp_building.php) с новой плашкой эргономичности.

## 3. Точки контракта между плагинами

| Контракт | Кто публикует | Кто потребляет | Назначение |
|----------|--------------|----------------|-----------|
| `do_action('wsc_buffer_recomputed', $building_id, $yard_id)` | `WSC_Writer::save_yard()` | `WSC_Ergo_Bridge::on_buffer_recomputed()` | Триггер пересчёта эргономики при обновлении буфера |
| `do_action('wsc_building_imported', $id, $row)` | `WSC_Writer::upsert_building()` | `WSC_Ergo_Bridge::on_building_imported()` | Триггер расчёта буфера сразу после импорта здания |
| Опция `wsergo_indicator_definitions` | `WSErgo_Indicators` (схема) + `WSC_Ergo_Bridge::ensure_indicators_registered()` + новый `WSErgo_Building_Indicators::register()` | `WSErgo_Indicators::get_definitions()` → форма админки, нормализация, плашка | Реестр всех индикаторов (id/dimension/vmin/vmax/direction/unit/weight) |
| Мета `_wsc_building_id` (на `wsp_yard` и `wsp_building`) | `WSC_Ergo_Bridge::sync_yard_post() / sync_building_post()` | `write_poi_indicators()`, REST `/refresh-osm`, плашка | Связь wsp_*-постов с OSM-зданием |
| Мета `wsergo_raw_<id>` | `WSC_Ergo_Bridge` (OSM) или `WSErgo_Admin` метабокс (manual) | `WSErgo_Indicators::compute_dimension_from_indicators()` | Сырые значения индикаторов |
| Мета `wsergo_raw_<id>_src` | те же | `write_poi_indicators()` (для приоритета manual), плашка (бейдж источника) | Источник данных: `osm` / `manual` |
| Мета `wsergo_dim_<dimension>` | `WSErgo_Indicators::sync_dimension_meta_from_indicators()` | `WSErgo_Model::get_scores_from_post()`, радар-чарт | Нормализованный балл 0–100 по измерению |
| Мета `wsergo_index` | `WSErgo_Calculator::compute_and_store_index()` | плашка, карта, REST | Сводный E |
| Опция `wsergo_dimension_weights` | админ-секция (новая в итерации) | `WSErgo_Model::get_weights()` → калькулятор | Веса 6 измерений (метод `integral`) |
| Опция `wsergo_weighting_method` | админ-секция (новая) | `WSErgo_Model::get_weights()` (новая ветка) | Выбор источника весов: `integral`/`ahp`/`reference`/`rf`/`ml_python` |
| Опция `wsergo_ahp_matrix` | админ-секция (новая) | `WSErgo_Settings::compute_ahp_weights()` | Матрица попарных сравнений 6×6 для метода AHP |
| `WorldStat_UI::chart()` / `::map()` / `::stats_grid()` | базовая платформа | `single-wsp_building.php` | UI-компоненты плашки (радар, карта здания) |
| `worldstat_single_template` filter | `worldstat-platform` (роутер шаблонов) | Ergonomics — подмена на свой `single-wsp_building.php` | Подключение шаблона страницы здания |

## 4. Что меняется в Courtyard в этой итерации

- [`class-wsc-landcover.php`](../includes/class-wsc-landcover.php) — единые списки kind для зелёных зон Overpass/`wsc_landuse`, карты (`wsc-landuse-fill`), эргономики и попапа двора; индикатор `park_area_within` учитывает расширенный набор `kind` (в т.ч. meadow/orchard/farmland, natural=`wood`|`grassland` и др.).
- [`class-wsc-overpass.php`](../includes/class-wsc-overpass.php) — `build_ql()` дополняется новыми тегами (см. п. 2).
- [`class-wsc-categories.php`](../includes/class-wsc-categories.php) — новые категории `infra_safety`, `infra_comfort`, `infra_func` в `tag_rules()` и `categorize()`.
- [`class-wsc-parser.php`](../includes/class-wsc-parser.php) — извлечение `sidewalk:width` и пометка footway-линий.
- [`class-wsc-ergo-bridge.php`](../includes/class-wsc-ergo-bridge.php) — основная работа итерации:
  - Починен баг с `dim_a..dim_f` в `POI_INDICATORS`/`ensure_indicators_registered()`: теперь используются реальные ключи из `WSErgo_Model::DIMENSION_KEYS` (`functionality`/`safety`/`comfort`/`livability`/`masterability`/`manageability`).
  - Добавлен `sync_building_post()` — автосоздание `wsp_building` для residential зданий рядом с `wsp_yard`.
  - `write_poi_indicators()` расширен расчётом дистанций (haversine от центроида до ближайшего POI типа `fire_hydrant|waste|parking|bicycle_parking|charging_station|drinking_water|bench|playground`), счётчиков в буфере и среднего `sidewalk:width` по footway. Записи дублируются на оба поста (yard + building) с парной метой `_src=osm`. Уважает `manual`-источник (не перебивает ручной ввод).

## 5. Что меняется в Ergonomics в этой итерации

- Новый класс [`class-ergo-building-indicators.php`](../../worldstat-ergonomics/includes/class-ergo-building-indicators.php) — регистрация ~15 определений `b_*` индикаторов в опции `wsergo_indicator_definitions` на активации и init. Использует общий контракт `WSErgo_Indicators::OPTION_DEFINITIONS`.
- Расширение [`class-ergo-admin.php`](../../worldstat-ergonomics/includes/class-ergo-admin.php) — метабокс ручного ввода для `wsp_building` (lux/dB/чек-листы) + админ-секция «Веса измерений и метод расчёта E» (6 полей весов + селектор `wsergo_weighting_method` + AHP-матрица 6×6).
- Расширение [`class-ergo-model.php`](../../worldstat-ergonomics/includes/class-ergo-model.php) — в `get_weights()` ветка для метода `ahp` (берёт веса из `WSErgo_Settings::compute_ahp_weights()`).
- Расширение [`class-ergo-settings.php`](../../worldstat-ergonomics/includes/class-ergo-settings.php) — `compute_ahp_weights( array $matrix ): array` (нормированное геометрическое среднее по строкам матрицы 6×6, см. *Saaty 1980*).
- Расширение [`templates/single-wsp_building.php`](../../worldstat-ergonomics/templates/single-wsp_building.php) — секция «Эргономичность дома»: большой E с цветовой индикацией, бейдж метода и версии методики, радар по 6 измерениям (`WorldStat_UI::chart` type=`radar`), аккордеон с таблицами индикаторов, кнопки REST `Пересчитать E` / `Обновить из OSM`.
- Новый файл [`assets/css/building-ergo-panel.css`](../../worldstat-ergonomics/assets/css/building-ergo-panel.css) — стили плашки. Подключение через расширенный [`WSErgo_Renderer::enqueue_public_assets()`](../../worldstat-ergonomics/includes/class-ergo-renderer.php) (добавлена ветка `is_singular(WSErgo_CPT::SLUG_BUILDING)`).
- Новый файл [`class-ergo-rest.php`](../../worldstat-ergonomics/includes/class-ergo-rest.php) — `POST /wsergo/v1/building/{id}/recompute` и `/refresh-osm`, регистрация на `rest_api_init`, capability `edit_post`.

## 6. Чего НЕ делаем в этой итерации

- MLP/Random Forest как реальные методы расчёта весов — placeholder в селекторе с пояснением «требует Python-сервиса».
- Метод «Опорные случаи» — placeholder.
- AHP-визард на странице здания — оставляем настройки в админке, попарные сравнения тут не вводятся.
- Карта с интерактивным выбором здания — работаем со страницей `single-wsp_building.php`.
- Для крупных зданий расстояние считается от центроида (не от ближайшей точки полигона) — приемлемая аппроксимация для MVP.

## 7. Совместимость и риски

- Footway без `width` — используется дефолт 1.0 м (отметить в логах bridge для последующего уточнения).
- Шум в OSM не представлен — `b_noise_db_day` заполняется только из админки. Если значения нет, индикатор корректно исключается из расчёта измерения `safety` (логика уже в `WSErgo_Indicators::compute_dimension_from_indicators()`).
- Ручной ввод имеет приоритет над OSM (пара `wsergo_raw_<id>` + `wsergo_raw_<id>_src=manual`). Перезапуск скана не должен затирать ручные значения — это поведение явно реализовано в новом `write_poi_indicators()`.
- Все hook-контракты (`wsc_buffer_recomputed`, опция `wsergo_indicator_definitions`, фильтр `wsergo_dimension_weights`) обратно совместимы — старые POI-индикаторы продолжают работать после починки маппинга измерений.
