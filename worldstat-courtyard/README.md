# WorldStat Courtyard — Придомовая территория

Расширение для **World Statistics Platform**, добавляющее вкладку «Придомовая территория»
на страницы стран. Сканирует здания и POI из OpenStreetMap (Overpass API или PBF-файлы),
классифицирует объекты по 8 категориям, строит буфер придомовой территории
(по умолчанию 35 м, настраивается покатегорийно) и публикует данные в кастомных таблицах
+ автоматически создаёт `wsp_yard` для жилых зданий с заполнением POI-индикаторов
для расчёта эргономичности.

## Зависимости

- WordPress ≥ 5.8, PHP ≥ 7.4
- World Statistics Platform (worldstat-core)
- WorldStat Cities (worldstat-cities)
- Опционально: php-geos или GDAL/ogr2ogr (для точного буфера)
- Опционально: osmium-tool в PATH (для PBF-импорта)
- Опционально: Action Scheduler (если активен — используется; иначе fallback на WP-Cron)

## Архитектура

```
Country page (wsp_country)
  └── Tab "Придомовая территория" (priority 30)
       └── Card grid городов (wsp_city) для текущей ISO2
            └── Клик → MapLibre canvas + кнопки скана
                 ├── Overpass scan (по плиткам bbox через Action Scheduler)
                 ├── PBF upload (osmium-tool)
                 └── 2D ↔ 3D switch (fill-extrusion + Three.js trees)
                      ↓
                 Custom tables: wsc_buildings / wsc_yards / wsc_pois / wsc_landuse
                      ↓
                 Авто wsp_yard для residential
                      ↓
                 wsergo_raw_poi_*_within → WSErgo_Indicators → 6-мерный индекс E
```

## REST API

Все эндпоинты под `wsc/v1/`:

| Method | Path | Назначение |
|--------|------|------------|
| GET    | `country/{iso2}/cities`         | Список городов страны со счётчиками |
| GET    | `city/{id}`                      | Сводка города + статус активного скана |
| POST   | `city/{id}/scan`                 | Запустить скан (mode: auto/viewport/drawn) |
| GET    | `city/{id}/scan/status`          | Прогресс активного скана |
| POST   | `city/{id}/upload-pbf`           | Загрузить PBF-файл и запустить импорт |
| POST   | `city/{id}/recompute-buffers`    | Пересчитать буферы (после смены настроек) |
| GET    | `city/{id}/layer/{layer}`        | GeoJSON FeatureCollection слоя (buildings/yards/pois/landuse) |
| GET    | `city/{id}/boundary`             | Граница города (Nominatim или fallback-круг) |
| GET    | `city/{id}/export.geojson?layer` | Экспорт слоя в файл |
| GET    | `basemap/{z}/{x}/{y}`            | Тайл из локального .mbtiles |
| GET    | `style.json?city={id}`           | MapLibre style JSON |

## Настройки

`Настройки → WS Courtyard`:

- Буфер по категориям (по умолчанию 35 м для жилых)
- Высоты по умолчанию для 3D
- Источник тайлов: OpenFreeMap / MapTiler / локальный MBTiles
- Язык наименований
- Endpoint Overpass, пути к osmium / ogr2ogr
- Редактор правил категорий (тег OSM → категория)

## 8 категорий

`residential`, `commercial`, `education`, `healthcare`, `sport_leisure`,
`office`, `industrial`, `other`. Цвета и буферы настраиваются.

## MBTiles

Положите `.mbtiles` файл в `wp-content/uploads/wsc/tiles/` и укажите его имя
в настройках (раздел «Карта»). Endpoint `/wsc/v1/basemap/{z}/{x}/{y}`
будет отдавать тайлы из SQLite (поддерживается vector .pbf и raster).

## Совместимость с эргономикой

Плагин публикует псевдонимы `WSOSM_Writer` и `WSOSM_Jobs_Import` (через `class_alias`),
которые ожидаются существующим кодом `worldstat-ergonomics` (см. `class-ergo-data.php`).
Метаключи: `wsosm_city_id`, `wsosm_entity_type`, `wsosm_address_full`, `wsosm_status`.
Индикаторы `poi_education_within`, `poi_healthcare_within`, `poi_shop_within`,
`poi_sport_within`, `poi_office_within`, `poi_industrial_within`, `park_area_within`,
`tree_count_within` регистрируются в `wsergo_indicator_definitions` автоматически
при первом импорте жилого здания.
