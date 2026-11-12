<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds any missing tenant profile columns and makes sure
     * tenant_documents has the correct structure.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'nationality')) {
                $table->string('nationality', 50)->nullable()->after('gender');
            }
            if (! Schema::hasColumn('tenants', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('address');
            }
            if (! Schema::hasColumn('tenants', 'start_electric_meter')) {
                $table->decimal('start_electric_meter', 10, 2)->default(0)->after('move_in_date');
            }
            if (! Schema::hasColumn('tenants', 'start_water_meter')) {
                $table->decimal('start_water_meter', 10, 2)->default(0)->after('start_electric_meter');
            }
        });

        if (! Schema::hasColumn('tenant_documents', 'path')) {
            Schema::dropIfExists('tenant_documents');

            Schema::create('tenant_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name');
                $table->string('mime_type', 100)->nullable();
                $table->unsignedInteger('size')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: this migration only repairs missing structure.
    }
};