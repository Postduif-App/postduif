<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * The key pair that lets this installation speak to a browser's push service.
 *
 * Web push has no account and no API token: a server proves it is the same
 * server the browser subscribed to by signing every push with a private key
 * whose public half the browser was handed at subscription time. Generating
 * that pair is therefore a one-time step of setting the application up, and it
 * belongs on the command line rather than in a vendor's dashboard.
 *
 * Printed, never written. Overwriting VAPID_PRIVATE_KEY in a running
 * installation silently invalidates every subscription that exists — the
 * browsers keep their old public key and reject anything signed with the new
 * one — so the paste into .env stays a deliberate act by a human.
 */
#[Signature('webpush:vapid')]
#[Description('Genereer een VAPID-sleutelpaar voor webpushmeldingen')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        ['publicKey' => $publicKey, 'privateKey' => $privateKey] = VAPID::createVapidKeys();

        $this->components->info('Zet deze regels in je .env:');

        $this->line('VAPID_PUBLIC_KEY='.$publicKey);
        $this->line('VAPID_PRIVATE_KEY='.$privateKey);
        $this->newLine();

        /*
         * VAPID_SUBJECT is not generated but chosen: RFC 8292 wants a way for a
         * push service to reach whoever runs this server when its pushes
         * misbehave, so it has to be an address that a person actually reads.
         */
        $this->components->warn('Vul VAPID_SUBJECT zelf in — een mailto:-adres of https:-URL waarop de beheerder van deze installatie bereikbaar is.');

        if (filled(config('services.webpush.private_key'))) {
            $this->components->warn('Let op: er staat al een sleutelpaar ingesteld. Vervangen betekent dat elk bestaand abonnement stopt met werken en opnieuw aangemaakt moet worden.');
        }

        return self::SUCCESS;
    }
}
