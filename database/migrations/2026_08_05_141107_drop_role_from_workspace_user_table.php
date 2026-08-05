<?php

use App\Enums\SystemRole;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The string is gone; the pointer beside it is the answer.
     *
     * It held one of four names, which was the whole of what a role could be.
     * A workspace writes its own roles now — see the workspace_roles table —
     * and "Leverancier" has no name in that enum to be written as, so the
     * column could only ever be right about the four the application ships
     * with and quietly wrong about every other.
     *
     * Everything that read it was moved off first: the admin panel's member
     * list, the dev quick-login, and the five places that asked "is this
     * person a guest" through wherePivot. Those last ones now ask the role row
     * whether it is external, which is the question they were really asking.
     */
    public function up(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Putting it back, with the best answer that still exists.
     *
     * The four built-in roles can be written back from the key on the role row.
     * A role a workspace wrote itself has no name here, so those rows fall to
     * the column's own default — which is the honest outcome rather than a
     * failure: this column never had a way to say what they are, and that is
     * exactly why it went.
     */
    public function down(): void
    {
        Schema::table('workspace_user', function (Blueprint $table) {
            $table->string('role')->default(SystemRole::Member->value);
        });

        foreach (SystemRole::cases() as $role) {
            DB::table('workspace_user')
                ->whereIn('workspace_role_id', Role::query()
                    ->where('key', $role->value)
                    ->select('id'))
                ->update(['role' => $role->value]);
        }
    }
};
