<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_description_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('status')->default('pending');

            /** Generated texts, keyed by attribute code. */
            $table->json('fields')->nullable();

            /**
             * The values these texts replaced, so a publish can be undone.
             * Not named "previous": Eloquent's Model already declares a protected
             * $previous, which shadows the attribute inside the model class.
             */
            $table->json('previous_values')->nullable();

            /** Validation findings that survived the retry, for the reviewer. */
            $table->json('problems')->nullable();

            $table->decimal('similarity', 5, 3)->nullable();
            $table->string('driver')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('error')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_description_drafts');
    }
};
