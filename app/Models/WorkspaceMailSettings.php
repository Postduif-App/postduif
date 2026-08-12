<?php

namespace App\Models;

use App\Enums\MailTransport;
use App\Enums\SmtpEncryption;
use Database\Factories\WorkspaceMailSettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where one workspace's mail leaves from.
 *
 * Singular in the plural, deliberately: there is one of these per workspace and
 * "de mailinstellingen" is a single thing you edit, not a list you add to. The
 * table carries the unique index that says so.
 *
 * Everything here is optional, including the transport, and a workspace with no
 * row at all behaves exactly like one that chose Default. That is the invariant
 * this whole feature hangs on — the application sent mail long before this
 * screen existed, and nothing about adding it may make an unconfigured
 * workspace stop.
 *
 * @property int $id
 * @property int $workspace_id
 * @property MailTransport $transport
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property SmtpEncryption|null $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $postmark_token
 * @property string|null $postmark_message_stream
 * @property string|null $lettermint_token
 * @property string|null $lettermint_route_id
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'transport',
    'from_address',
    'from_name',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
    'smtp_password',
    'postmark_token',
    'postmark_message_stream',
    'lettermint_token',
    'lettermint_route_id',
])]
class WorkspaceMailSettings extends Model
{
    /** @use HasFactory<WorkspaceMailSettingsFactory> */
    use HasFactory;

    /**
     * Spelled out rather than guessed. Eloquent would land on the same name
     * here by accident — "Settings" pluralises to itself — and a convention
     * that only happens to hold is one that breaks on the next rename.
     */
    protected $table = 'workspace_mail_settings';

    /**
     * A row that exists but has never been given a transport is the same as no
     * row at all, and both mean the application's own mailer.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'transport' => MailTransport::Default->value,
    ];

    /**
     * verified_at and last_error are not fillable and are not meant to be.
     * They are what happened, not what somebody typed — only sending a real
     * message may write them, so that a green tick on this screen always means
     * a message actually left.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport' => MailTransport::class,
            'smtp_port' => 'integer',
            'smtp_encryption' => SmtpEncryption::class,
            /*
             * Encrypted rather than hashed, unlike a webhook token: these are
             * credentials we have to be able to present to somebody else. A
             * hash would be unusable, so the protection that is available is
             * that a copy of this table is worthless without APP_KEY.
             */
            'smtp_password' => 'encrypted',
            'postmark_token' => 'encrypted',
            'lettermint_token' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whether this is filled in far enough to send anything.
     *
     * Asked before building a mailer rather than trusted from validation,
     * because the two do not see the same rows: validation guards what this
     * screen writes, and a half-filled row can also come from a seeder, a
     * console command, or a transport whose required fields grew after the row
     * was saved. A workspace that is not ready falls back to the application
     * mailer, which is the one failure mode nobody notices.
     */
    public function isUsable(): bool
    {
        return match ($this->transport) {
            MailTransport::Default => false,
            MailTransport::Smtp => filled($this->smtp_host) && filled($this->smtp_port),
            MailTransport::Postmark => filled($this->postmark_token),
            MailTransport::Lettermint => filled($this->lettermint_token),
        };
    }

    /**
     * This row as something Mail::build understands, or null when it is not
     * ready to send.
     *
     * The point of naming the enum cases after Laravel's own transport names:
     * the shape below is nearly the config array a mailer entry would have had
     * in config/mail.php, so nothing here has to know how a transport is
     * constructed — only which keys it reads.
     *
     * @return array<string, mixed>|null
     */
    public function mailerConfig(): ?array
    {
        if (! $this->isUsable()) {
            return null;
        }

        $config = match ($this->transport) {
            MailTransport::Smtp => [
                'transport' => 'smtp',
                'host' => $this->smtp_host,
                'port' => $this->smtp_port,
                'username' => $this->smtp_username,
                'password' => $this->smtp_password,
                ...($this->smtp_encryption ?? SmtpEncryption::StartTls)->transportOptions(),
            ],
            MailTransport::Postmark => array_filter([
                'transport' => 'postmark',
                'token' => $this->postmark_token,
                'message_stream_id' => $this->postmark_message_stream,
            ], fn (?string $value): bool => $value !== null),
            MailTransport::Lettermint => array_filter([
                'transport' => 'lettermint',
                'token' => $this->lettermint_token,
                'route_id' => $this->lettermint_route_id,
            ], fn (?string $value): bool => $value !== null),
            /*
             * Unreachable: isUsable() has already turned Default away. Here so
             * that a case added to the enum without a branch here is a type
             * error rather than a workspace that quietly sends nothing.
             */
            MailTransport::Default => null,
        };

        if ($config === null) {
            return null;
        }

        $from = $this->fromAddress();

        return $from === null ? $config : [...$config, 'from' => $from];
    }

    /**
     * The sender to put on every message, or null to keep the application's.
     *
     * A name without an address is not a sender, so the address is what decides
     * whether this block exists at all — MailManager reads 'address' and treats
     * anything else as absent.
     *
     * @return array{address: string, name: string|null}|null
     */
    public function fromAddress(): ?array
    {
        if (blank($this->from_address)) {
            return null;
        }

        return ['address' => $this->from_address, 'name' => $this->from_name];
    }

    /**
     * Remember that a message really left.
     *
     * Clears the previous failure in the same breath: a screen that showed both
     * a green tick and last week's error would be asking the reader to work out
     * which of the two is current.
     */
    public function markVerified(): void
    {
        $this->forceFill(['verified_at' => now(), 'last_error' => null])->save();
    }

    /**
     * Remember why it did not.
     *
     * verified_at is left alone. "This worked on the 3rd and is failing now" is
     * a more useful thing for the screen to be able to say than either half.
     */
    public function markFailed(string $error): void
    {
        $this->forceFill(['last_error' => $error])->save();
    }
}
