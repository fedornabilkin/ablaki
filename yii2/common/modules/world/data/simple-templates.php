<?php
// code => [name, type, level, parents, work seconds, materials, settings]
return [
    'world' => ['Мир', 'WORLD', 1, [], 1, [], []],
    'region' => ['Регион', 'REGION', 2, ['world'], 1, [], []],
    'city' => ['Город', 'SETTLEMENT', 3, ['region'], 1, [], ['settlement_kind' => 'city']],
    'starter-site' => ['Стоянка', 'PLOT', 4, ['city'], 30, [], ['plot_kind' => 'campsite', 'allow_building' => 1, 'area' => 25]],
    'estate' => ['Усадьба', 'PLOT', 4, ['city'], 120, ['classic-plank' => 8], ['plot_kind' => 'campsite', 'allow_building' => 1, 'area' => 25]],
    'starter-shelter' => ['Шалаш', 'BUILDING', 5, ['starter-site', 'estate'], 60, ['classic-plank' => 4, 'classic-rope' => 2], ['building_kind' => 'shelter']],
    'house' => ['Дом', 'BUILDING', 5, ['starter-site', 'estate'], 300, ['classic-plank' => 12, 'classic-stone' => 8], ['building_kind' => 'house']],
    'forge' => ['Кузница', 'BUILDING', 5, ['starter-site', 'estate'], 300, ['classic-plank' => 8, 'classic-stone' => 12], ['building_kind' => 'forge']],
    'garden' => ['Огород', 'PLOT', 5, ['starter-site', 'estate'], 60, [], ['plot_kind' => 'garden', 'allow_building' => 0, 'fertility' => 100]],
    'mine' => ['Шахта', 'BUILDING', 5, ['starter-site', 'estate'], 180, ['classic-plank' => 8, 'classic-stone' => 4], ['building_kind' => 'mine']],
    'bedroom' => ['Комната', 'ROOM', 6, ['house'], 60, ['classic-plank' => 4], ['exposure_class' => 'indoor', 'area' => 4]],
    'workroom' => ['Мастерская', 'ROOM', 6, ['house', 'forge'], 90, ['classic-plank' => 6], ['exposure_class' => 'indoor', 'area' => 6]],
    'garden-bed' => ['Грядка', 'BED', 6, ['garden'], 30, [], ['unlocked' => 1]],
    'chest' => ['Сундук', 'CHEST', 6, ['house', 'forge', 'starter-shelter'], 60, ['classic-plank' => 4], []],
    'place' => ['Место', 'PLACE', 7, ['bedroom', 'workroom', 'garden-bed', 'chest'], 10, [], []],
    'shelf' => ['Полка', 'PLACE', 7, ['bedroom', 'workroom', 'chest'], 30, ['classic-plank' => 2], []],
];
