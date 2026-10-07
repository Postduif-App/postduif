<?php

namespace App\Http\Controllers;

use App\Actions\UploadLinks\CreateUploadLink;
use App\Http\Requests\StoreUploadLinkRequest;
use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\MediaStream;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Putting an upload link out, closing it, and fetching what came in.
 *
 * Inside the app, beside the transfers: it is the same ordinary work turned
 * around. The half a customer sees is PublicUploadLinkController.
 */
class UploadLinkController extends Controller
{
    public function store(
        StoreUploadLinkRequest $request,
        Workspace $workspace,
        CreateUploadLink $createUploadLink,
    ): RedirectResponse {
        $createUploadLink->handle(
            workspace: $workspace,
            owner: $request->user(),
            title: $request->string('title')->trim()->value(),
            validForDays: $request->integer('valid_for_days'),
            message: $request->string('message')->trim()->value() ?: null,
            maxUploads: $request->input('max_uploads') === null
                ? null
                : $request->integer('max_uploads'),
            password: $request->string('password')->value() ?: null,
            notifyChannel: $request->notifyChannel(),
        );

        // The link itself is not flashed: it is on the list the member lands
        // back on, where it can be copied — see TransferController::store().
        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.upload_link.created')]);

        return back();
    }

    /**
     * Close it.
     *
     * Marked rather than deleted: whoever follows the link later deserves to
     * hear it was closed rather than that it never existed, and what already
     * came in stays fetchable until the prune that follows.
     */
    public function destroy(Workspace $workspace, UploadLink $uploadLink): RedirectResponse
    {
        abort_unless($uploadLink->workspace_id === $workspace->id, 404);

        $this->authorize('delete', $uploadLink);

        if (! $uploadLink->isRevoked()) {
            $uploadLink->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.upload_link.withdrawn')]);

        return back();
    }

    /** One file somebody sent in. */
    public function download(Workspace $workspace, UploadLink $uploadLink, Media $media): BinaryFileResponse
    {
        $this->authorizeFetch($workspace, $uploadLink);

        abort_unless($media->model_type === (new UploadLinkSubmission)->getMorphClass(), 404);
        abort_unless(
            $uploadLink->submissions()->whereKey($media->model_id)->exists(),
            404,
        );

        $path = $media->getPath();
        abort_unless(is_file($path), 404);

        $response = response()->file($path, [
            /*
             * Always an attachment, and never with the type the sender's
             * browser claimed. A stranger chose these bytes: an .html served
             * inline here would run its script on our origin, signed in as the
             * member who opened it.
             */
            'Content-Disposition' => 'attachment; filename="'.addslashes($media->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Content-Type', 'application/octet-stream');

        return $response;
    }

    /** Everything one person sent in, as one archive. */
    public function downloadSubmission(
        Workspace $workspace,
        UploadLink $uploadLink,
        UploadLinkSubmission $submission,
    ): MediaStream {
        $this->authorizeFetch($workspace, $uploadLink);

        abort_unless($submission->upload_link_id === $uploadLink->id, 404);

        $files = $submission->files();
        abort_if($files->isEmpty(), 404);

        $name = str($uploadLink->title.' '.$submission->name)->slug()->append('.zip')->value();

        return MediaStream::create($name)->addMedia($files);
    }

    private function authorizeFetch(Workspace $workspace, UploadLink $uploadLink): void
    {
        abort_unless($uploadLink->workspace_id === $workspace->id, 404);

        $this->authorize('view', $uploadLink);
    }
}
