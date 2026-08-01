<?php

use App\Enums\AttachmentType;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The list every workspace started with, before the composer could record.
     *
     * @var array<int, string>
     */
    private const ORIGINAL = ['images', 'video', 'documents'];

    /**
     * Let existing workspaces record a voice note too.
     *
     * Only the ones that never touched the setting. A workspace still carrying
     * the exact list it was created with never made a choice about audio — that
     * list came from a default written before there was anything to record
     * with. Adding to it is finishing the default, not overruling anybody.
     *
     * A workspace that has edited its list has said something, even if what it
     * said was the same three groups in a different order. Those are left
     * alone: somebody who deliberately unticked audio should not find it back
     * on after a deploy.
     */
    public function up(): void
    {
        Workspace::query()->cursor()->each(function (Workspace $workspace): void {
            $current = $workspace->allowed_attachment_types ?? [];

            // Compared as a set, so the order the JSON came back in does not
            // decide it — jsonb does not keep the order it was given.
            if (array_diff($current, self::ORIGINAL) !== []
                || array_diff(self::ORIGINAL, $current) !== []) {
                return;
            }

            $workspace->forceFill([
                'allowed_attachment_types' => AttachmentType::defaults(),
            ])->save();
        });
    }

    /**
     * Deliberately empty. Taking audio away again would remove a group that
     * people may have been using in the meantime, and this migration cannot
     * tell those workspaces from the ones it changed.
     */
    public function down(): void {}
};
