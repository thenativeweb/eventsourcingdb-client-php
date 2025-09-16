<?php

declare(strict_types=1);

$composerJsonFile = __DIR__ . '/../../composer.json';
$composerJsonData = file_get_contents($composerJsonFile);
if ($composerJsonData === false) {
    throw new RuntimeException('Failed to read composer.json file');
}

$composerData = json_decode($composerJsonData, true);
if (!isset($composerData['require']['php'])) {
    throw new RuntimeException('PHP version not found in composer.json');
}

$minPhpVersion = $composerData['require']['php'];
$minPhpVersion = str_replace('>=', '', $minPhpVersion);

$phpWatchJsonData = file_get_contents('https://php.watch/api/v1/versions');
if ($phpWatchJsonData === false) {
    throw new RuntimeException('Failed to fetch PHP versions from php.watch API');
}

$phpWatchData = json_decode($phpWatchJsonData, true);
if (!isset($phpWatchData['data'])) {
    throw new RuntimeException('Invalid data format received from php.watch API');
}

$phpMatrix = array_filter(
    $phpWatchData['data'],
    function (array $item) use ($minPhpVersion): bool {
        return $item['name'] >= $minPhpVersion && $item['isNextVersion'] === false;
    },
);

$phpVersions = array_map(
    static fn (array $version): string => $version['name'],
    $phpMatrix,
);

sort($phpVersions);

echo json_encode(array_values($phpVersions));