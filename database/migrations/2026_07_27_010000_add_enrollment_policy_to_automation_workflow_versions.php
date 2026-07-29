<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_workflow_versions', function (Blueprint $table) {
            $table->string('enrollment_policy', 30)->nullable()->after('version');
        });

        DB::table('automation_workflow_versions')
            ->orderBy('id')
            ->eachById(function (object $version): void {
                $policy = DB::table('automation_workflows')
                    ->where('id', $version->workflow_id)
                    ->value('enrollment_policy');

                DB::table('automation_workflow_versions')
                    ->where('id', $version->id)
                    ->update(['enrollment_policy' => $policy ?? 'every_event']);
            });
    }

    public function down(): void
    {
        Schema::table('automation_workflow_versions', function (Blueprint $table) {
            $table->dropColumn('enrollment_policy');
        });
    }
};
