<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Adds the column only if it does not exist yet, so it is safe in every case
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_occupants', 'occupation')) {
            Schema::table('tenant_occupants', function (Blueprint $table) {
                $table->string('occupation', 100)->nullable()->after('relationship');
            });
        }
    }

    public function down(): void
    {
        // Nothing to undo: the create migration owns this column.
    }
};