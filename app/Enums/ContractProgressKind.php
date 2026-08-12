<?php

namespace App\Enums;

/**
 * Which piece of news about a contract is being delivered.
 *
 * Three, and they are kept apart because the difference between them is the
 * whole value of the notification. "Anna heeft getekend, nog twee te gaan" and
 * "iedereen is langs geweest" ask different things of the person reading them,
 * and a feature that sent the same words for both would teach somebody to skim
 * past the one that means the work is done.
 *
 * A refusal is here beside the other two rather than treated as a failure. It
 * is an outcome — see DeclineContract — and it is the one of the three the
 * author most urgently needs to read, because it is the only one that means
 * something has to change.
 */
enum ContractProgressKind: string
{
    case Signed = 'signed';
    case Declined = 'declined';
    case Completed = 'completed';
}
