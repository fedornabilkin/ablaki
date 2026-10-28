<?php
// Draft balancing values. Content is versioned; the seed never updates a published revision.
return [
    ['code' => 'starter-site', 'kind' => 'PLOT', 'config' => ['name' => 'Стоянка', 'plot_kind' => 'campsite', 'area' => 4, 'allow_building' => true, 'transferable' => false, 'exposure_class' => 'outdoor']],
    ['code' => 'starter-shelter', 'kind' => 'BUILDING', 'config' => ['name' => 'Шалаш', 'lodging_places' => 1, 'station_slots' => 0, 'grant_code' => 'world-starter-shelter', 'portable' => true]],
    ['code' => 'house', 'kind' => 'BUILDING', 'config' => ['name' => 'Дом', 'price' => '100.0000', 'currency' => 'Cr', 'duration_seconds' => 3600, 'rooms' => ['bedroom', 'workroom'], 'initial_open' => 1, 'limit' => 6, 'base_price' => '30.0000']],
    ['code' => 'forge', 'kind' => 'BUILDING', 'config' => ['name' => 'Кузница', 'price' => '120.0000', 'currency' => 'Cr', 'duration_seconds' => 3600, 'rooms' => ['workroom'], 'initial_open' => 1, 'limit' => 6, 'base_price' => '40.0000']],
    ['code' => 'market', 'kind' => 'BUILDING', 'config' => ['name' => 'Рынок', 'price' => '150.0000', 'currency' => 'Cr', 'duration_seconds' => 7200, 'initial_open' => 1, 'limit' => 10, 'base_price' => '30.0000']],
    ['code' => 'club', 'kind' => 'BUILDING', 'config' => ['name' => 'Клуб', 'price' => '160.0000', 'currency' => 'Cr', 'duration_seconds' => 7200, 'initial_open' => 1, 'limit' => 12, 'base_price' => '20.0000']],
    ['code' => 'warehouse', 'kind' => 'BUILDING', 'config' => ['name' => 'Склад', 'price' => '100.0000', 'currency' => 'Cr', 'duration_seconds' => 3600, 'initial_open' => 1, 'limit' => 10, 'base_price' => '25.0000']],
    ['code' => 'hospital', 'kind' => 'BUILDING', 'config' => ['name' => 'Больница', 'price' => '180.0000', 'currency' => 'Cr', 'duration_seconds' => 7200, 'initial_open' => 1, 'limit' => 10, 'base_price' => '35.0000']],
    ['code' => 'farm', 'kind' => 'BUILDING', 'config' => ['name' => 'Ферма', 'price' => '100.0000', 'currency' => 'Cr', 'duration_seconds' => 3600, 'initial_open' => 1, 'limit' => 8, 'base_price' => '20.0000']],
    ['code' => 'town-hall', 'kind' => 'BUILDING', 'config' => ['name' => 'Ратуша', 'price' => '200.0000', 'currency' => 'Cr', 'duration_seconds' => 7200, 'admin_only' => true]],
    ['code' => 'garden', 'kind' => 'PLOT', 'config' => ['name' => 'Огород', 'price' => '30.0000', 'currency' => 'Cr', 'plot_kind' => 'garden', 'initial_open' => 1, 'limit' => 10, 'base_price' => '10.0000', 'curve' => 'linear']],
    ['code' => 'bedroom', 'kind' => 'ROOM', 'config' => ['name' => 'Спальня', 'price' => '30.0000', 'currency' => 'Cr', 'exposure_class' => 'indoor', 'lodging_places' => 1, 'station_slots' => 0]],
    ['code' => 'workroom', 'kind' => 'ROOM', 'config' => ['name' => 'Мастерская', 'price' => '40.0000', 'currency' => 'Cr', 'exposure_class' => 'indoor', 'station_slots' => 2]],
    ['code' => 'canopy', 'kind' => 'ROOM', 'config' => ['name' => 'Навес', 'price' => '20.0000', 'currency' => 'Cr', 'exposure_class' => 'covered', 'station_slots' => 2]],
];
