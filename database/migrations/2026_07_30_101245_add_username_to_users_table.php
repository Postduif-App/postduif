<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A mention needs something short and unambiguous to type. Display names
     * are neither — "Fenna de Vries" contains spaces, and two people may share
     * one. So every user gets a handle, unique across the installation.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->after('name');
        });

        User::query()->whereNull('username')->eachById(function (User $user) {
            $user->forceFill(['username' => $this->handleFor($user)])->save();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    /**
     * Derive a handle from the display name, falling back to the local part of
     * the email, and append a counter until it is free.
     */
    private function handleFor(User $user): string
    {
        $base = Str::of($user->name)->slug('.')->limit(30, '')->value()
            ?: Str::of($user->email)->before('@')->slug('.')->value();

        $handle = $base;
        $suffix = 1;

        while (User::where('username', $handle)->whereKeyNot($user->id)->exists()) {
            $handle = $base.++$suffix;
        }

        return $handle;
    }
};
