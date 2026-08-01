<?php

namespace App\Enums;

/**
 * What happened to a ticket, other than somebody saying something.
 *
 * These are what makes a status worth showing: "wacht op klant" with no record
 * of who put it there and when is a claim nobody can check. Comments are kept
 * apart from these on purpose — a comment can be edited and withdrawn, an event
 * is what happened and stays.
 */
enum TicketEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case DueDateChanged = 'due_date_changed';
}
