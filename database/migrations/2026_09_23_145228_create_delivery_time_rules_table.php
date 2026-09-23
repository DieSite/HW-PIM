<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery time of every rug of a brand, edited on Tools → Levertijden
     * and resolved per variant by App\Services\DeliveryTimeService. The time
     * for a rug in the HW showroom applies to every brand, so it lives in
     * core_config instead of on a brand row (with a default in the service).
     */
    public function up(): void
    {
        Schema::create('delivery_time_rules', function (Blueprint $table) {
            $table->id();
            $table->string('brand')->unique();
            $table->string('in_stock')->nullable();
            $table->string('out_of_stock')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('delivery_time_rules')->insert(array_map(
            fn (array $rule): array => [...$rule, 'created_at' => $now, 'updated_at' => $now],
            [
                ['brand' => 'De Munk', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '8 tot 12 weken'],
                ['brand' => 'Karpi', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
                ['brand' => 'Mart Visser', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
                ['brand' => 'Mart Visser|Karpi', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
                ['brand' => 'Eurogros', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '3 tot 5 weken'],
                ['brand' => 'Desso', 'in_stock' => '1 tot 2 weken', 'out_of_stock' => '1 tot 2 weken'],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_time_rules');
    }
};
