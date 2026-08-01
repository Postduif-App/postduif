<?php

use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('accent')
                ->default(WorkspaceAccent::Neutral->value)
                ->after('blocked_words');

            $table->string('font')
                ->default(WorkspaceFont::InstrumentSans->value)
                ->after('accent');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['accent', 'font']);
        });
    }
};
