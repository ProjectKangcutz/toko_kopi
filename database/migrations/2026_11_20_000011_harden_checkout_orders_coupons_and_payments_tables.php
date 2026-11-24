<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DDL MySQL 8 melakukan implicit commit per statement ALTER TABLE.
     * Keamanan dijaga melalui pre-flight validation sebelum DDL pertama
     * serta guard idempoten berbasis skema dan definisi index per langkah.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // --- PRE-FLIGHT CHECKS (Read-only, dijalankan sebelum DDL apa pun) ---
        foreach (['orders', 'order_items', 'coupons', 'coupon_usages', 'payments'] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException("Tabel wajib '{$requiredTable}' tidak ditemukan untuk database hardening.");
            }
        }

        $requiredColumnsByTable = [
            'orders' => ['guest_token'],
            'order_items' => ['variant_name_snapshot', 'sku_snapshot', 'unit_price_snapshot'],
            'coupons' => ['min_purchase_amount'],
            'coupon_usages' => ['coupon_id', 'order_id'],
            'payments' => ['snap_redirect_url'],
        ];

        foreach ($requiredColumnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException(
                        "Kolom wajib '{$table}.{$column}' tidak ditemukan. Migration database hardening dibatalkan."
                    );
                }
            }
        }

        $ordersIdempotencyIndexExists = $this->assertIndexMatchesOrAbsent(
            'orders',
            'orders_checkout_idempotency_key_unique',
            ['checkout_idempotency_key'],
            true
        );

        $couponUsageUniqueExists = $this->assertIndexMatchesOrAbsent(
            'coupon_usages',
            'coupon_usages_coupon_order_unique',
            ['coupon_id', 'order_id'],
            true
        );

        if (! $ordersIdempotencyIndexExists && Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            $hasDuplicateOrderKeys = DB::table('orders')
                ->whereNotNull('checkout_idempotency_key')
                ->select('checkout_idempotency_key')
                ->groupBy('checkout_idempotency_key')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicateOrderKeys) {
                throw new RuntimeException(
                    'Ditemukan nilai duplikat non-null pada orders.checkout_idempotency_key. '
                    .'Bersihkan duplikasi terlebih dahulu sebelum menerapkan unique index.'
                );
            }
        }

        if (! $couponUsageUniqueExists) {
            $hasDuplicateCouponUsages = DB::table('coupon_usages')
                ->select('coupon_id', 'order_id')
                ->groupBy('coupon_id', 'order_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasDuplicateCouponUsages) {
                throw new RuntimeException(
                    'Ditemukan data duplikat (coupon_id, order_id) pada tabel coupon_usages. '
                    .'Bersihkan data duplikat terlebih dahulu sebelum menerapkan unique index.'
                );
            }
        }

        $shouldExpandSnapRedirectUrl = $this->needsSnapRedirectUrlExpansion();

        // --- ADDITIVE & IDEMPOTENT DDL ---
        if (! Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('checkout_idempotency_key', 64)
                    ->nullable()
                    ->after('guest_token');
            });
        }

        if (! $ordersIdempotencyIndexExists) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('checkout_idempotency_key', 'orders_checkout_idempotency_key_unique');
            });
        }

        $needProductImageSnapshot = ! Schema::hasColumn('order_items', 'product_image_snapshot');
        $needRegularPriceSnapshot = ! Schema::hasColumn('order_items', 'regular_price_snapshot');
        $needIsPromoSnapshot = ! Schema::hasColumn('order_items', 'is_promo_snapshot');

        if ($needProductImageSnapshot || $needRegularPriceSnapshot || $needIsPromoSnapshot) {
            Schema::table('order_items', function (Blueprint $table) use (
                $needProductImageSnapshot,
                $needRegularPriceSnapshot,
                $needIsPromoSnapshot
            ) {
                if ($needProductImageSnapshot) {
                    $table->string('product_image_snapshot', 500)
                        ->nullable()
                        ->after('variant_name_snapshot');
                }

                if ($needRegularPriceSnapshot) {
                    $table->decimal('regular_price_snapshot', 15, 2)
                        ->nullable()
                        ->after('sku_snapshot');
                }

                if ($needIsPromoSnapshot) {
                    $table->boolean('is_promo_snapshot')
                        ->default(false)
                        ->after('unit_price_snapshot');
                }
            });
        }

        if (! Schema::hasColumn('coupons', 'stackable_with_product_promo')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->boolean('stackable_with_product_promo')
                    ->default(false)
                    ->after('min_purchase_amount');
            });
        }

        if (! $couponUsageUniqueExists) {
            Schema::table('coupon_usages', function (Blueprint $table) {
                $table->unique(['coupon_id', 'order_id'], 'coupon_usages_coupon_order_unique');
            });
        }

        if ($shouldExpandSnapRedirectUrl) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('snap_redirect_url', 500)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // --- PRE-FLIGHT CHECK ROLLBACK (sebelum DDL rollback pertama) ---
        $shouldShrinkSnapRedirectUrl = false;

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'snap_redirect_url')) {
            $currentLength = $this->resolveVarcharLength('payments', 'snap_redirect_url');

            if ($currentLength === 500) {
                $maxLength = (int) (DB::table('payments')
                    ->whereNotNull('snap_redirect_url')
                    ->selectRaw('MAX(CHAR_LENGTH(snap_redirect_url)) as max_len')
                    ->value('max_len') ?? 0);

                if ($maxLength > 255) {
                    throw new RuntimeException(
                        "Rollback dibatalkan: terdapat nilai payments.snap_redirect_url sepanjang {$maxLength} karakter (> 255)."
                    );
                }

                $shouldShrinkSnapRedirectUrl = true;
            }
        }

        $dropOrdersIdempotencyIndex = false;
        if (Schema::hasTable('orders')) {
            $idx = $this->findIndex('orders', 'orders_checkout_idempotency_key_unique');
            if ($idx !== null) {
                if (! $this->indexMatches($idx, ['checkout_idempotency_key'], true)) {
                    throw new RuntimeException(
                        "Rollback dibatalkan: definisi index 'orders_checkout_idempotency_key_unique' bukan milik migration ini."
                    );
                }
                $dropOrdersIdempotencyIndex = true;
            }
        }

        $dropCouponUsageUnique = false;
        if (Schema::hasTable('coupon_usages')) {
            $idx = $this->findIndex('coupon_usages', 'coupon_usages_coupon_order_unique');
            if ($idx !== null) {
                if (! $this->indexMatches($idx, ['coupon_id', 'order_id'], true)) {
                    throw new RuntimeException(
                        "Rollback dibatalkan: definisi index 'coupon_usages_coupon_order_unique' bukan milik migration ini."
                    );
                }

                // Pastikan index pendukung foreign key coupon_id dan order_id tetap tersedia setelah unique index dihapus.
                $remainingIndexes = array_values(array_filter(
                    Schema::getIndexes('coupon_usages'),
                    static fn (array $item): bool => strtolower((string) ($item['name'] ?? '')) !== 'coupon_usages_coupon_order_unique'
                ));

                $hasCouponIdSupport = false;
                $hasOrderIdSupport = false;

                foreach ($remainingIndexes as $remaining) {
                    $firstCol = strtolower((string) ($remaining['columns'][0] ?? ''));
                    if ($firstCol === 'coupon_id') {
                        $hasCouponIdSupport = true;
                    }
                    if ($firstCol === 'order_id') {
                        $hasOrderIdSupport = true;
                    }
                }

                if (! $hasCouponIdSupport || ! $hasOrderIdSupport) {
                    throw new RuntimeException(
                        'Rollback dibatalkan: index pendukung foreign key pada coupon_usages (coupon_id / order_id) tidak tersedia.'
                    );
                }

                $dropCouponUsageUnique = true;
            }
        }

        // --- ROLLBACK DDL ---
        if ($shouldShrinkSnapRedirectUrl) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('snap_redirect_url', 255)->nullable()->change();
            });
        }

        if ($dropCouponUsageUnique) {
            Schema::table('coupon_usages', function (Blueprint $table) {
                $table->dropUnique('coupon_usages_coupon_order_unique');
            });
        }

        if (Schema::hasTable('coupons') && Schema::hasColumn('coupons', 'stackable_with_product_promo')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('stackable_with_product_promo');
            });
        }

        if (Schema::hasTable('order_items')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('order_items', 'product_image_snapshot') ? 'product_image_snapshot' : null,
                Schema::hasColumn('order_items', 'regular_price_snapshot') ? 'regular_price_snapshot' : null,
                Schema::hasColumn('order_items', 'is_promo_snapshot') ? 'is_promo_snapshot' : null,
            ]));

            if ($columns !== []) {
                Schema::table('order_items', function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }

        if ($dropOrdersIdempotencyIndex) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique('orders_checkout_idempotency_key_unique');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'checkout_idempotency_key')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('checkout_idempotency_key');
            });
        }
    }

    private function findIndex(string $table, string $indexName): ?array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strtolower((string) ($index['name'] ?? '')) === strtolower($indexName)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $expectedColumns
     */
    private function indexMatches(array $index, array $expectedColumns, bool $expectedUnique): bool
    {
        $actualColumns = array_values(array_map(
            static fn (string $column): string => strtolower($column),
            $index['columns'] ?? []
        ));
        $normalizedExpected = array_values(array_map('strtolower', $expectedColumns));

        return (bool) ($index['unique'] ?? false) === $expectedUnique
            && $actualColumns === $normalizedExpected;
    }

    /**
     * @param  array<int, string>  $expectedColumns
     */
    private function assertIndexMatchesOrAbsent(
        string $table,
        string $indexName,
        array $expectedColumns,
        bool $expectedUnique = true
    ): bool {
        $index = $this->findIndex($table, $indexName);

        if ($index === null) {
            return false;
        }

        if (! $this->indexMatches($index, $expectedColumns, $expectedUnique)) {
            $actualColumns = array_map('strtolower', $index['columns'] ?? []);
            $actualDesc = ((bool) ($index['unique'] ?? false) ? 'UNIQUE' : 'NON-UNIQUE').'('.implode(', ', $actualColumns).')';
            $expectedDesc = ($expectedUnique ? 'UNIQUE' : 'NON-UNIQUE').'('.implode(', ', array_map('strtolower', $expectedColumns)).')';

            throw new RuntimeException(
                "Definisi index '{$indexName}' pada tabel '{$table}' tidak sesuai: ditemukan {$actualDesc}, diharapkan {$expectedDesc}."
            );
        }

        return true;
    }

    private function needsSnapRedirectUrlExpansion(): bool
    {
        $column = $this->findColumn('payments', 'snap_redirect_url');
        if ($column === null) {
            throw new RuntimeException(
                "Kolom wajib 'payments.snap_redirect_url' tidak ditemukan saat memeriksa kebutuhan ekspansi."
            );
        }

        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $fullType = strtolower(trim((string) ($column['type'] ?? '')));

        if (in_array($typeName, ['text', 'mediumtext', 'longtext'], true)) {
            return false;
        }

        if ($typeName === 'varchar') {
            $length = $this->resolveVarcharLength('payments', 'snap_redirect_url');
            if ($length === null) {
                throw new RuntimeException(
                    "Panjang VARCHAR kolom 'payments.snap_redirect_url' tidak dapat dikenali dari definisi '{$fullType}'."
                );
            }

            return $length < 500;
        }

        throw new RuntimeException(
            "Tipe kolom 'payments.snap_redirect_url' tidak dikenali untuk ekspansi: "
            ."type_name='{$typeName}', type='{$fullType}'."
        );
    }

    private function findColumn(string $table, string $columnName): ?array
    {
        foreach (Schema::getColumns($table) as $column) {
            if (strtolower((string) ($column['name'] ?? '')) === strtolower($columnName)) {
                return $column;
            }
        }

        return null;
    }

    private function resolveVarcharLength(string $table, string $columnName): ?int
    {
        $column = $this->findColumn($table, $columnName);
        if ($column === null) {
            return null;
        }

        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $type = strtolower(trim((string) ($column['type'] ?? '')));

        if ($typeName !== '' && $typeName !== 'varchar') {
            return null;
        }

        if (preg_match('/^varchar\s*\(\s*(\d+)\s*\)$/i', $type, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
};
