<?php

namespace App\Features;

class Transfers extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Bestanden versturen';
    }

    public static function description(): string
    {
        return 'Bestanden klaarzetten achter een deelbare downloadlink, ook voor mensen buiten de workspace.';
    }

    /**
     * Off until somebody says otherwise, for the reason AiAccess is: this hands
     * files to something outside the workspace. The link is the whole of the
     * proof that the holder was meant to have them, and a workspace should have
     * decided out loud that such a link may exist at all.
     */
    public static function default(): bool
    {
        return false;
    }
}
