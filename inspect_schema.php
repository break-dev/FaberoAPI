<?php

/**
 * inspect_schema.php
 *
 * Lista todas las tablas de la BD configurada y sus columnas.
 * Útil cuando aparece un "Unknown column" en una query y necesitás
 * saber qué columnas existen realmente.
 *
 * Uso:
 *   php inspect_schema.php                 # todas las tablas
 *   php inspect_schema.php vehiculo        # detalle de una tabla
 *   php inspect_schema.php particion       # detalle de una tabla
 *   php inspect_schema.php lote_guia       # detalle de una tabla
 *   php inspect_schema.php guia_primer_tramo
 *
 * Carga el bootstrap de Laravel para tener acceso a DB::, config y .env.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$arg = $argv[1] ?? null;

if ($arg) {
    // Detalle de una tabla
    if (! Schema::hasTable($arg)) {
        fwrite(STDERR, "La tabla `{$arg}` no existe.\n");
        $all = collect(Schema::getTableListing())->sort()->values();
        fwrite(STDERR, "Tablas disponibles: " . $all->implode(', ') . "\n");
        exit(1);
    }

    echo "Tabla: {$arg}\n";
    echo str_repeat('=', 80) . "\n";

    $columns = Schema::getColumns($arg);
    if (empty($columns)) {
        echo "(no se pudieron leer columnas — verificá el driver)\n";
    } else {
        printf("%-30s %-20s %-10s %-10s %s\n", 'Columna', 'Tipo', 'Nullable', 'Default', 'Key');
        echo str_repeat('-', 80) . "\n";
        foreach ($columns as $col) {
            printf(
                "%-30s %-20s %-10s %-10s %s\n",
                $col['name'] ?? '?',
                $col['type'] ?? '?',
                ! empty($col['nullable']) ? 'YES' : 'NO',
                $col['default'] ?? 'NULL',
                $col['key'] ?? '',
            );
        }
    }

    // Índices
    $indexes = Schema::getIndexes($arg);
    if (! empty($indexes)) {
        echo "\nÍndices:\n";
        foreach ($indexes as $idx) {
            $cols = $idx['columns'] ?? [];
            $name = $idx['name'] ?? '?';
            $unique = ! empty($idx['unique']) ? 'UNIQUE' : '';
            echo sprintf("  %-30s %-10s %s\n", $name, $unique, implode(',', (array) $cols));
        }
    }

    exit(0);
}

// Listado completo
echo "Base de datos: " . config('database.connections.mysql.database') . "\n";
echo "Driver: " . config('database.default') . "\n";
echo str_repeat('=', 80) . "\n";

$tables = collect(Schema::getTableListing())->sort()->values();

foreach ($tables as $table) {
    echo "\n• {$table}\n";
    $columns = Schema::getColumns($table);
    foreach ($columns as $col) {
        $nullable = ! empty($col['nullable']) ? 'NULL' : 'NOT NULL';
        $default = $col['default'] ?? '';
        $defaultStr = $default === '' ? '' : "DEFAULT={$default}";
        $key = $col['key'] ?? '';
        $keyStr = $key ? " [{$key}]" : '';
        printf(
            "    %-32s %-20s %-10s %s%s\n",
            $col['name'] ?? '?',
            $col['type'] ?? '?',
            $nullable,
            $defaultStr,
            $keyStr,
        );
    }
}
