<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'banner_image_path')) {
                $table->string('banner_image_path')->nullable()->after('content');
            }

            if (! Schema::hasColumn('pages', 'show_in_footer')) {
                $table->boolean('show_in_footer')->default(false)->index()->after('is_published');
            }

            if (! Schema::hasColumn('pages', 'sort_order')) {
                $table->integer('sort_order')->default(0)->index()->after('show_in_footer');
            }
        });

        $defaultPolicies = [
            'kebijakan-privasi' => 1,
            'syarat-ketentuan' => 2,
            'kebijakan-pengiriman' => 3,
            'kebijakan-pengembalian' => 4,
        ];

        foreach ($defaultPolicies as $slug => $order) {
            DB::table('pages')
                ->where('slug', $slug)
                ->update([
                    'show_in_footer' => true,
                    'sort_order' => $order,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $columns = [];
            foreach (['banner_image_path', 'show_in_footer', 'sort_order'] as $column) {
                if (Schema::hasColumn('pages', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
