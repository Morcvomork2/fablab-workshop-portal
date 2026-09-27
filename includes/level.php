<?php
/**
 * Erfahrungslevel-Logik für das FabLab Workshop-Portal.
 * Basiert auf der Anzahl bestätigter (nicht stornierter) Anmeldungen.
 */

function getLevelInfo(int $anzahl): array {
    $levels = [
        ['min' => 0,  'name' => 'Starter'],
        ['min' => 2,  'name' => 'Tüftler'],
        ['min' => 4,  'name' => 'Erfinder'],
        ['min' => 6,  'name' => 'Halb Mensch, halb Maschine'],
        ['min' => 9,  'name' => 'FabLab-Meister'],
        ['min' => 10, 'name' => 'Innovations-Guru'],
        ['min' => 12, 'name' => 'FabLab-Legende'],
    ];

    $currentLevel = $levels[0];
    $nextLevel = $levels[1];

    foreach ($levels as $i => $level) {
        if ($anzahl >= $level['min']) {
            $currentLevel = $level;
            $nextLevel = $levels[$i + 1] ?? null;
        }
    }

    $isMax = $nextLevel === null;
    $progressCurrent = $isMax ? $anzahl : $anzahl - $currentLevel['min'];
    $progressMax = $isMax ? 1 : $nextLevel['min'] - $currentLevel['min'];
    $progressPercent = $isMax ? 100 : (int)round(($progressCurrent / $progressMax) * 100);

    return [
        'name'             => $currentLevel['name'],
        'next_name'        => $nextLevel ? $nextLevel['name'] : null,
        'next_threshold'   => $nextLevel ? $nextLevel['min'] : null,
        'remaining'        => $nextLevel ? $nextLevel['min'] - $anzahl : 0,
        'progress_percent' => $progressPercent,
        'is_max'           => $isMax,
        'anzahl'           => $anzahl,
    ];
}
