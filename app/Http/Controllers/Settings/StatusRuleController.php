<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Availability;
use App\Http\Controllers\Controller;
use App\Models\StatusRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rules that set somebody's status for them.
 *
 * Entirely their own: a status is what a person says about themselves, so
 * nobody else makes or reads these. Order matters and is stored, because first
 * match wins — see StatusRule.
 */
class StatusRuleController extends Controller
{
    /** More than anybody needs, low enough that the list stays a list. */
    private const MAX_RULES = 20;

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/status-rules', [
            'rules' => $this->rulesFor($request),
            'timezone' => $user->timezone,
            /*
             * Which rule is in force at this very moment, so the list can say
             * so. A list of rules where you cannot see which one is currently
             * winning is a list you have to work out in your head.
             */
            'activeRuleId' => $user->activeStatusRule()?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if(
            $user->statusRules()->count() >= self::MAX_RULES,
            422,
            __('chat.too_many_status_rules', ['count' => self::MAX_RULES]),
        );

        $user->statusRules()->create([
            ...$this->validated($request),
            // New rules go underneath, where they cannot silently outrank
            // something that was already working.
            'position' => ($user->statusRules()->max('position') ?? -1) + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.rule.added')]);

        return back();
    }

    public function update(Request $request, StatusRule $statusRule): RedirectResponse
    {
        $this->authorizeOwnership($request, $statusRule);

        $statusRule->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.rule.updated')]);

        return back();
    }

    public function destroy(Request $request, StatusRule $statusRule): RedirectResponse
    {
        $this->authorizeOwnership($request, $statusRule);

        $statusRule->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.rule.removed')]);

        return back();
    }

    /**
     * Put the rules in a new order.
     *
     * The whole list at once rather than a move-one-up endpoint: order is a
     * property of the list, and sending one change at a time would let two
     * quick clicks arrive in the wrong sequence.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $own = $user->statusRules()->pluck('id')->all();

        // Anything not theirs is dropped rather than refused: the list is
        // rebuilt from what remains, so a stale id cannot reorder someone else.
        $ordered = array_values(array_filter(
            $validated['ids'],
            fn (int $id): bool => in_array($id, $own, true),
        ));

        foreach ($ordered as $position => $id) {
            $user->statusRules()->whereKey($id)->update(['position' => $position]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'days' => ['array'],
            // ISO weekdays. Empty means every day, which is how "always" and
            // the catch-all rule underneath everything else are written.
            'days.*' => ['integer', 'between:1,7'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            // Deliberately not "after starts_at": a window that ends earlier
            // than it begins runs through midnight, and that is a real thing
            // somebody means.
            'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at'],
            'status_emoji' => ['nullable', 'string', 'max:16'],
            'status_text' => ['nullable', 'string', 'max:100'],
            'availability' => ['required', Rule::enum(Availability::class)],
        ]);
    }

    /**
     * A rule belongs to one person and nobody else may touch it. 404 rather
     * than 403, so an id cannot be probed for existence.
     */
    private function authorizeOwnership(Request $request, StatusRule $rule): void
    {
        abort_unless($rule->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rulesFor(Request $request): array
    {
        return $request->user()->statusRules()->get()
            ->map(fn (StatusRule $rule): array => [
                'id' => $rule->id,
                'days' => $rule->days,
                // Trimmed to what a form field takes: Postgres hands back
                // "09:00:00" and an <input type="time"> wants "09:00".
                'startsAt' => $rule->starts_at === null ? null : substr($rule->starts_at, 0, 5),
                'endsAt' => $rule->ends_at === null ? null : substr($rule->ends_at, 0, 5),
                'statusEmoji' => $rule->status_emoji,
                'statusText' => $rule->status_text,
                'availability' => $rule->availability->value,
            ])->all();
    }
}
