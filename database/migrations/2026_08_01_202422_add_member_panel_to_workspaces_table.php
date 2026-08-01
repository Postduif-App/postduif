<?php

use App\Enums\MemberPanelVisibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Off, so no existing workspace wakes up with a second sidebar it
            // never asked for.
            $table->string('member_panel')
                ->default(MemberPanelVisibility::Off->value)
                ->after('channel_creation');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('member_panel');
        });
    }
};
