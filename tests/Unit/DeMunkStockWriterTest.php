<?php

use App\Services\DeMunkStockWriter;

it('maps De Munk articles to identity => size => free stock', function () {
    $map = DeMunkStockWriter::buildStockMap([
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 170, 'Lengte' => 240, 'ArticleStockFree' => 3],
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 250, 'Lengte' => 350, 'ArticleStockFree' => 2],
    ]);

    expect($map)->toBe([
        'MODERN|DIAMANTE|DI-01' => [
            '170 cm x 240 cm' => 3,
            '250 cm x 350 cm' => 2,
        ],
    ]);
});

it('keeps the highest free stock when a size appears twice', function () {
    $map = DeMunkStockWriter::buildStockMap([
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 170, 'Lengte' => 240, 'ArticleStockFree' => 1],
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 170, 'Lengte' => 240, 'ArticleStockFree' => 4],
    ]);

    expect($map['MODERN|DIAMANTE|DI-01']['170 cm x 240 cm'])->toBe(4);
});

it('skips articles missing collection, quality, colour or dimensions', function () {
    $map = DeMunkStockWriter::buildStockMap([
        ['_collectie' => '', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 170, 'Lengte' => 240, 'ArticleStockFree' => 3],
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => 'DI-01', 'Breedte' => 0, 'Lengte' => 240, 'ArticleStockFree' => 3],
        ['_collectie' => 'MODERN', '_kwaliteit' => 'DIAMANTE', 'EersteKleurVeld' => '', 'Breedte' => 170, 'Lengte' => 240, 'ArticleStockFree' => 3],
    ]);

    expect($map)->toBe([]);
});

it('defaults free stock to zero when the article omits it', function () {
    $map = DeMunkStockWriter::buildStockMap([
        ['_collectie' => 'BASIC', '_kwaliteit' => 'CORRADO', 'EersteKleurVeld' => 'CO-02', 'Breedte' => 200, 'Lengte' => 250],
    ]);

    expect($map['BASIC|CORRADO|CO-02']['200 cm x 250 cm'])->toBe(0);
});
