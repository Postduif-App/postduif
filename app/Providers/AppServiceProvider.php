<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Support\Dns\DnsHostResolver;
use App\Support\Dns\HostResolver;
use App\Support\PlatformStatistics;
use App\Workflows\Actions\AddChannelMembers;
use App\Workflows\Actions\AddReaction;
use App\Workflows\Actions\ArchiveChannel;
use App\Workflows\Actions\CreateChannel;
use App\Workflows\Actions\CreateTicket;
use App\Workflows\Actions\Delay;
use App\Workflows\Actions\GetChannelInfo;
use App\Workflows\Actions\HttpRequest;
use App\Workflows\Actions\PinMessage;
use App\Workflows\Actions\RemoveReaction;
use App\Workflows\Actions\ReplyInThread;
use App\Workflows\Actions\SendChannelMessage;
use App\Workflows\Actions\SendDirectMessage;
use App\Workflows\Actions\UnarchiveChannel;
use App\Workflows\Actions\UnpinMessage;
use App\Workflows\Triggers\ButtonTrigger;
use App\Workflows\Triggers\ChannelJoinTrigger;
use App\Workflows\Triggers\FormSubmittedTrigger;
use App\Workflows\Triggers\LinkTrigger;
use App\Workflows\Triggers\MessageKeywordTrigger;
use App\Workflows\Triggers\ReactionTrigger;
use App\Workflows\Triggers\ScheduleTrigger;
use App\Workflows\Triggers\SlashCommandTrigger;
use App\Workflows\Triggers\TimeclockTrigger;
use App\Workflows\Triggers\WebhookTrigger;
use App\Workflows\WorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * What a hostname stands for is the security decision behind link
         * previews — see PublicUrl. Bound rather than newed up, so a test can
         * answer that question without asking the machine's own resolver, which
         * on a development box points *.test at localhost.
         */
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        $this->registerWorkflowRegistry();
    }

    /**
     * Every trigger and every action a workflow may be built from.
     *
     * The order is the order somebody picking one sees, so it is a choice
     * rather than an accident of the filesystem — the same reasoning as
     * WorkspaceFeature::ALL, and the register says why it is an object here
     * instead of a const.
     *
     * The triggers run from the ones a workspace sets up for itself to the ones
     * something outside sets off, because that is the order people think in:
     * "when somebody says X" is what they came for, and the webhook is what
     * they find later.
     */
    private function registerWorkflowRegistry(): void
    {
        $this->app->singleton(WorkflowRegistry::class, fn (): WorkflowRegistry => new WorkflowRegistry(
            triggers: [
                MessageKeywordTrigger::class,
                ChannelJoinTrigger::class,
                ReactionTrigger::class,
                /*
                 * Among the things people do in the workspace rather than down
                 * with the webhook, even though a form can be filled in from
                 * outside over a public link. What somebody looks for here is
                 * "when an answer comes in", and that sits with the message and
                 * the emoji — the link is a detail of the form, not of this.
                 */
                FormSubmittedTrigger::class,
                /*
                 * Among the things people do rather than down with the clock's
                 * own screen: what somebody looks for here is "wanneer iemand
                 * inklokt", which belongs beside joining a channel.
                 */
                TimeclockTrigger::class,
                LinkTrigger::class,
                /*
                 * Beside the link trigger, because the two are the manual pair:
                 * one starts from a message that exists, the other from an
                 * empty field. Somebody looking for "ik wil het zelf aanzetten"
                 * should find both in one place.
                 */
                SlashCommandTrigger::class,
                // The third of the manual three, and the one that waits in the
                // channel rather than being reached for.
                ButtonTrigger::class,
                ScheduleTrigger::class,
                WebhookTrigger::class,
            ],
            /*
             * Grouped the way somebody picking one thinks: saying something
             * first, then the things you do to a message that already exists,
             * then the channel itself, and waiting last — it is the only one
             * that is not a thing done to anything.
             */
            actions: [
                SendChannelMessage::class,
                SendDirectMessage::class,
                ReplyInThread::class,
                CreateTicket::class,
                AddReaction::class,
                RemoveReaction::class,
                PinMessage::class,
                UnpinMessage::class,
                CreateChannel::class,
                AddChannelMembers::class,
                GetChannelInfo::class,
                HttpRequest::class,
                ArchiveChannel::class,
                UnarchiveChannel::class,
                Delay::class,
            ],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureOAuth();

        /*
         * By class name rather than a closure: the about command resolves it
         * from the container and calls it only while rendering, so the counts
         * stay out of every web request that boots this provider. See
         * PlatformStatistics for what is counted and why it lives here.
         */
        AboutCommand::add('Postduif', PlatformStatistics::class);

        // Chat is unusable without a websocket server, so "php artisan dev"
        // starts Reverb alongside the HTTP server, queue worker and Vite.
        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * How somebody is asked whether an AI client may act as them.
     *
     * The package ships a screen and it works; this one is ours because of
     * where it is shown. An OAuth client opens it in a window with its own name
     * in the address bar, which makes the page the only thing telling the
     * reader whose account is about to be handed over — so it carries the brand
     * and says the address out loud, like every other screen somebody meets
     * from outside the application.
     */
    private function configureOAuth(): void
    {
        /*
         * Through response()->view() rather than view(): Passport asks for
         * something it can send, and a View is a thing that renders rather than
         * a thing with a status on it.
         *
         * @param  array<string, mixed>  $parameters
         */
        Passport::authorizationView(
            fn (array $parameters): Response => response()->view('mcp.authorize', $parameters),
        );

        /*
         * An access token outlives the conversation it was granted in but not
         * by much: a client that has gone quiet for a fortnight is one somebody
         * has stopped using, and a grant nobody remembers giving is exactly the
         * kind that should have run out. The refresh token is the thing that
         * keeps a client in use working without asking again.
         */
        Passport::tokensExpireIn(now()->addDays(14));
        Passport::refreshTokensExpireIn(now()->addDays(90));
    }

    /**
     * A webhook is a machine with a single credential, so it gets a budget of
     * its own rather than sharing one with everything coming from the same IP —
     * one noisy integration must not be able to silence the others.
     *
     * Keyed by the hash of the token rather than the token, so a secret never
     * ends up as a cache key.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('webhook', fn (Request $request) => Limit::perMinute(60)
            ->by(Webhook::hashToken((string) $request->route('token'))));

        /*
         * A workflow trigger, on a shorter leash than a message webhook. What
         * is behind this one is not one message in one channel but a row of
         * steps that may post in several and put people in them — so twelve a
         * minute, which is plenty for anything reporting an event and far too
         * few to fill a workspace with.
         */
        RateLimiter::for('workflow-webhook', fn (Request $request) => Limit::perMinute(12)
            ->by(Workflow::hashWebhookToken((string) $request->route('token'))));

        /*
         * The token API, keyed per token for the same reason a webhook is: one
         * script polling too eagerly must not be able to lock out somebody
         * else's.
         *
         * Hashed, so a credential never becomes a cache key. Falls back to the
         * IP when there is no token at all — that request is going to be
         * refused anyway, and an unauthenticated caller hammering the endpoint
         * is exactly what a limit is for.
         */
        RateLimiter::for('api-token', fn (Request $request) => Limit::perMinute(60)
            ->by(ApiToken::hashToken((string) $request->bearerToken()) ?: $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
