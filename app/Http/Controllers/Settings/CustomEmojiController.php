<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Workspace\StoreCustomEmoji;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\AttachmentType;
use App\Http\Controllers\Controller;
use App\Models\CustomEmoji;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pictures a workspace gives its own names to.
 *
 * A screen of its own rather than a block on the general settings page: this is
 * a list that grows, each row has a file behind it, and the page it would have
 * shared is a form with one field on it.
 *
 * Uploading is for whoever manages the workspace. Slack lets anybody add one
 * and it is a reasonable choice, but an emoji is workspace-wide furniture that
 * everybody then sees in their picker — so it starts where the rest of the
 * workspace's furniture is decided, and can be widened later by giving it a
 * right of its own.
 */
class CustomEmojiController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Enough for a workspace with in-jokes, few enough that the picker stays a
     * thing you scan rather than search.
     */
    private const MAX_EMOJI = 200;

    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        return Inertia::render('settings/workspace-emoji', [
            'emoji' => $workspace->customEmoji()->with('author:id,name')->get()
                ->map(fn (CustomEmoji $emoji): array => [
                    'id' => $emoji->id,
                    'name' => $emoji->name,
                    'shortcode' => $emoji->shortcode(),
                    'url' => $emoji->url(),
                    // Null once the uploader has left. The emoji stays; who put
                    // it there is a detail of the row, not what holds it up.
                    'author' => $emoji->author?->name,
                    'createdAt' => $emoji->created_at?->toIso8601String(),
                ])->all(),

            'maxEmoji' => self::MAX_EMOJI,
            'workspace' => $workspace->name,
        ]);
    }

    public function store(Request $request, StoreCustomEmoji $storeCustomEmoji): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        abort_if(
            $workspace->customEmoji()->count() >= self::MAX_EMOJI,
            422,
            __('workspace_emoji.too_many', ['count' => self::MAX_EMOJI]),
        );

        /*
         * Lower case before the unique rule reads it. ":SHIPIT:" and ":shipit:"
         * are the same emoji to everybody who types one, so they had better be
         * the same row — and the index can only promise that if nothing arrives
         * in a second spelling.
         */
        $request->merge([
            'name' => mb_strtolower(trim($request->string('name')->value())),
        ]);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'regex:'.CustomEmoji::NAME_PATTERN,
                Rule::unique('custom_emoji', 'name')->where('workspace_id', $workspace->id),
            ],
            'image' => [
                'required',
                'image',
                /*
                 * Small on purpose. This is drawn at the height of a line of
                 * text, and a GIF is stored exactly as it arrives — nothing
                 * shrinks it afterwards, so this is the size it will be served
                 * at forever.
                 */
                'max:512',
                // The image group, so an SVG cannot come in — a script in a
                // costume, exactly as with attachments and avatars.
                'mimetypes:'.implode(',', AttachmentType::Images->mimeTypes()),
            ],
        ], [
            'name.regex' => __('requests.custom_emoji.name'),
            'name.unique' => __('requests.custom_emoji.taken'),
            'image.mimetypes' => __('requests.image.type'),
        ]);

        /** @var User $author */
        $author = $request->user();

        $emoji = $storeCustomEmoji->handle(
            workspace: $workspace,
            name: $validated['name'],
            file: $request->file('image'),
            author: $author,
        );

        return back()->with('status', __('flashes.custom_emoji.added', ['name' => $emoji->shortcode()]));
    }

    public function destroy(Request $request, CustomEmoji $customEmoji, StoreCustomEmoji $storeCustomEmoji): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        $this->authorizeEmoji($workspace, $customEmoji);

        $shortcode = $customEmoji->shortcode();

        $storeCustomEmoji->remove($customEmoji);

        return back()->with('status', __('flashes.custom_emoji.removed', ['name' => $shortcode]));
    }

    /**
     * That this emoji belongs to the workspace being managed.
     *
     * A 404 rather than a 403: an id from another workspace is not something
     * this member was refused, it is something they have no way of knowing
     * exists.
     */
    private function authorizeEmoji(Workspace $workspace, CustomEmoji $emoji): void
    {
        abort_unless($emoji->workspace_id === $workspace->id, 404);
    }
}
