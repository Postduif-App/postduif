<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\Webhook;
use App\Models\Workflow;
use App\Support\Dns\DnsHostResolver;
use App\Support\Dns\HostResolver;
use App\Support\PlatformStatistics;
use App\Support\Transcription\NullTranscriber;
use App\Support\Transcription\Transcriber;
use App\Support\Transcription\WhisperTranscriber;
use App\Workflows\Actions\AddChannelMembers;
use App\Workflows\Actions\AddReaction;
use App\Workflows\Actions\AppendToDocument;
use App\Workflows\Actions\ArchiveChannel;
use App\Workflows\Actions\AssignTicket;
use App\Workflows\Actions\CancelContract;
use App\Workflows\Actions\ClockOut;
use App\Workflows\Actions\ClosePoll;
use App\Workflows\Actions\CommentOnTicket;
use App\Workflows\Actions\CreateChannel;
use App\Workflows\Actions\CreateDocument;
use App\Workflows\Actions\CreateInviteLink;
use App\Workflows\Actions\CreatePoll;
use App\Workflows\Actions\CreateSecretRequest;
use App\Workflows\Actions\CreateTicket;
use App\Workflows\Actions\Delay;
use App\Workflows\Actions\ForwardMessage;
use App\Workflows\Actions\GetChannelInfo;
use App\Workflows\Actions\HttpRequest;
use App\Workflows\Actions\PinMessage;
use App\Workflows\Actions\PostContractToChannel;
use App\Workflows\Actions\PostToBoard;
use App\Workflows\Actions\RemindContractSigners;
use App\Workflows\Actions\RemoveReaction;
use App\Workflows\Actions\ReplyInThread;
use App\Workflows\Actions\RetryContractRender;
use App\Workflows\Actions\SendChannelMessage;
use App\Workflows\Actions\SendContractFromTemplate;
use App\Workflows\Actions\SendDirectMessage;
use App\Workflows\Actions\SendSignedContract;
use App\Workflows\Actions\SummariseHours;
use App\Workflows\Actions\UnarchiveChannel;
use App\Workflows\Actions\UnpinMessage;
use App\Workflows\Actions\UpdateTicket;
use App\Workflows\Triggers\ButtonTrigger;
use App\Workflows\Triggers\ChannelJoinTrigger;
use App\Workflows\Triggers\ChannelShareAnsweredTrigger;
use App\Workflows\Triggers\ChannelShareOfferedTrigger;
use App\Workflows\Triggers\ChannelShareRevokedTrigger;
use App\Workflows\Triggers\ContractCancelledTrigger;
use App\Workflows\Triggers\ContractCompletedTrigger;
use App\Workflows\Triggers\ContractDeclinedTrigger;
use App\Workflows\Triggers\ContractExpiredTrigger;
use App\Workflows\Triggers\ContractOpenedTrigger;
use App\Workflows\Triggers\ContractRenderFailedTrigger;
use App\Workflows\Triggers\ContractSentTrigger;
use App\Workflows\Triggers\ContractSignedTrigger;
use App\Workflows\Triggers\DocumentCreatedTrigger;
use App\Workflows\Triggers\DocumentDeletedTrigger;
use App\Workflows\Triggers\FormSubmittedTrigger;
use App\Workflows\Triggers\InviteLinkRedeemedTrigger;
use App\Workflows\Triggers\LinkTrigger;
use App\Workflows\Triggers\MessageKeywordTrigger;
use App\Workflows\Triggers\PollClosedTrigger;
use App\Workflows\Triggers\PollCreatedTrigger;
use App\Workflows\Triggers\PollVotedTrigger;
use App\Workflows\Triggers\ReactionTrigger;
use App\Workflows\Triggers\ScheduleTrigger;
use App\Workflows\Triggers\SecretRequestAnsweredTrigger;
use App\Workflows\Triggers\SlashCommandTrigger;
use App\Workflows\Triggers\TicketChangedTrigger;
use App\Workflows\Triggers\TicketCommentedTrigger;
use App\Workflows\Triggers\TicketCreatedTrigger;
use App\Workflows\Triggers\TicketStaleTrigger;
use App\Workflows\Triggers\TimeclockTrigger;
use App\Workflows\Triggers\TransferDownloadedTrigger;
use App\Workflows\Triggers\WebhookTrigger;
use App\Workflows\WorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use Lettermint\Laravel\Exceptions\ApiTokenNotFoundException;
use Lettermint\Laravel\Transport\LettermintTransportFactory;
use Lettermint\Lettermint;

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

        /*
         * Which transcriber a recorded huddle is handed to, decided once here
         * rather than checked wherever a transcript is wanted. With nothing
         * configured this is the one that refuses with a sentence — see
         * NullTranscriber, and the reason it refuses instead of returning
         * nothing.
         */
        $this->app->bind(Transcriber::class, function (): Transcriber {
            $url = config('services.transcription.url');

            if (! is_string($url) || $url === '') {
                return new NullTranscriber;
            }

            return new WhisperTranscriber(
                $url,
                config('services.transcription.token'),
                (string) config('services.transcription.model'),
                (int) config('services.transcription.timeout'),
            );
        });

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
                /*
                 * The eight contract moments, in the order a contract lives
                 * through them rather than by how often they are reached for.
                 * They read as a story that way, and somebody scanning for the
                 * one they want finds it by where it sits in that story.
                 *
                 * Together they are nearly half the list, which is the honest
                 * cost of a feature with eight distinct moments — see
                 * ContractTrigger for why this is not one trigger with a
                 * dropdown.
                 */
                ContractSentTrigger::class,
                ContractOpenedTrigger::class,
                ContractSignedTrigger::class,
                ContractDeclinedTrigger::class,
                ContractCompletedTrigger::class,
                ContractCancelledTrigger::class,
                ContractExpiredTrigger::class,
                ContractRenderFailedTrigger::class,
                /*
                 * The four ticket moments. Four where the contracts have eight,
                 * because everything that happens to a ticket carries the same
                 * cargo and the differences fit in a dropdown — see
                 * TicketTrigger, which sets that rule out.
                 */
                TicketCreatedTrigger::class,
                TicketChangedTrigger::class,
                TicketCommentedTrigger::class,
                TicketStaleTrigger::class,
                /*
                 * Two for documents and three for polls, both cut by the same
                 * rule as the rest: a trigger per moment something can act on,
                 * and none for the moments that fire on typing.
                 */
                DocumentCreatedTrigger::class,
                DocumentDeletedTrigger::class,
                PollCreatedTrigger::class,
                PollVotedTrigger::class,
                PollClosedTrigger::class,
                /*
                 * The governance handful: who is being let in, and which rooms
                 * are shared with whom. They sit at the end of the workspace's
                 * own happenings and before the manual three.
                 */
                InviteLinkRedeemedTrigger::class,
                ChannelShareOfferedTrigger::class,
                ChannelShareAnsweredTrigger::class,
                ChannelShareRevokedTrigger::class,
                TransferDownloadedTrigger::class,
                SecretRequestAnsweredTrigger::class,
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
                UpdateTicket::class,
                AssignTicket::class,
                CommentOnTicket::class,
                CreateDocument::class,
                AppendToDocument::class,
                CreatePoll::class,
                ClosePoll::class,
                /*
                 * The clock's two, which round off the trigger it has had all
                 * along: a workspace could hang a workflow off somebody
                 * clocking and then do nothing about it.
                 */
                ClockOut::class,
                SummariseHours::class,
                CreateInviteLink::class,
                CreateSecretRequest::class,
                PostToBoard::class,
                ForwardMessage::class,
                /*
                 * The contract handful, in the order a contract needs them:
                 * send one, nudge whoever is quiet, put it where people can see
                 * it, stop it, hand out the finished copy, and only then the
                 * repair. They sit here rather than at the end because they
                 * belong with opening a ticket — things a workflow does *for*
                 * somebody, as opposed to the things it does to a message.
                 */
                SendContractFromTemplate::class,
                RemindContractSigners::class,
                PostContractToChannel::class,
                CancelContract::class,
                SendSignedContract::class,
                RetryContractRender::class,
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
        $this->configureLettermintTransport();

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
     * Let a Lettermint mailer carry its own key.
     *
     * The package registers this driver already, but it builds the API client
     * from the application's global token and ignores the mailer's own config —
     * which is fine for an application that sends as itself, and useless for
     * one where every workspace brings its own account. This replaces the
     * driver with a version that reads $config['token'] first and falls back to
     * exactly what the package would have used, so nothing that does not set
     * mail settings notices the difference.
     *
     * Registered in boot rather than register: package providers are booted
     * before the application's own, so this runs second and wins. Postmark
     * needs none of this — Laravel's own driver already reads $config['token'].
     */
    private function configureLettermintTransport(): void
    {
        Mail::extend('lettermint', function (array $config = []): LettermintTransportFactory {
            $token = $config['token']
                ?? config('lettermint.token')
                ?? config('services.lettermint.token');

            if (! is_string($token)) {
                throw ApiTokenNotFoundException::create();
            }

            return new LettermintTransportFactory(
                Lettermint::email($token, timeout: (int) config('lettermint.timeout', 15)),
                $config,
            );
        });
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
         * Incoming mail, on a far longer leash than either of the above. What
         * arrives here is not one integration's choice of pace but a mailbox:
         * a customer forwarding a thread, a monitoring system that fired
         * twenty alerts at once, a morning's post arriving in a burst. Too low
         * a limit here does not slow anything down — it drops mail, which is
         * the one thing a letterbox may never do.
         *
         * Keyed by the hash of the token, like the two above, so a secret never
         * becomes a cache key.
         */
        RateLimiter::for('inbound-mail', fn (Request $request) => Limit::perMinute(120)
            ->by(hash('sha256', (string) $request->route('token'))));

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

        /*
         * Sending a contract, which is the one call in this API that puts mail
         * in a stranger's inbox from an address a workspace owns. Ten a minute
         * is generous for the thing it is — a lease, a quotation, a set of
         * terms — and mean enough that a loop in somebody's integration cannot
         * turn a workspace's mail domain into a source of complaints before
         * anybody notices. The screens' own send route is limited at the same
         * number for the same reason.
         */
        RateLimiter::for('contract-send', fn (Request $request) => Limit::perMinute(10)
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

        /*
         * A relation read off a model nobody loaded it on is a query, and one
         * inside a loop drawing a sidebar is a query per row. Everywhere but
         * production that is now an exception with a stack trace pointing at
         * the line, so the suite is what finds it rather than a slow page.
         *
         * Off in production for the obvious reason: a page that would have been
         * a little slow must not become a page that is a 500.
         */
        Model::preventLazyLoading(! app()->isProduction());

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
