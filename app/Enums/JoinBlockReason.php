<?php

namespace App\Enums;

/**
 * Why a user cannot join a room right now, as decided by the server.
 */
enum JoinBlockReason: string
{
    case AlreadyJoined = 'already_joined';
    case SeatReserved = 'seat_reserved';
    case Starting = 'starting';
    case Disqualified = 'disqualified';
    case NotYetOpen = 'not_yet_open';
    case RegistrationClosed = 'registration_closed';
    case Full = 'full';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
    case LoginRequired = 'login_required';
    case AccountRestricted = 'account_restricted';
    case EmailNotVerified = 'email_not_verified';
    case PaidEntryUnavailable = 'paid_entry_unavailable';
    case InsufficientBalance = 'insufficient_balance';
    case PaymentFailed = 'payment_failed';
    case JoiningUnavailable = 'joining_unavailable';

    public function label(): string
    {
        return match ($this) {
            self::AlreadyJoined => "You're registered",
            self::SeatReserved => 'Seat reserved',
            self::Starting => 'Starting soon',
            self::Disqualified => 'Not eligible',
            self::NotYetOpen => 'Registration not open',
            self::RegistrationClosed => 'Registration closed',
            self::Full => 'Room full',
            self::InProgress => 'Competition live',
            self::Ended => 'Competition ended',
            self::Cancelled => 'Cancelled',
            self::LoginRequired => 'Log in to join',
            self::AccountRestricted => 'Account restricted',
            self::EmailNotVerified => 'Verify your email',
            self::PaidEntryUnavailable => 'Paid entry unavailable',
            self::InsufficientBalance => 'Add money to join',
            self::PaymentFailed => 'Entry not completed',
            self::JoiningUnavailable => 'Joining opens soon',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::AlreadyJoined => 'Your seat in this room is confirmed.',
            self::SeatReserved => 'Your seat is held while your entry payment is completed. Unpaid reservations expire automatically.',
            self::Starting => 'Registration has closed and the competition is about to start.',
            self::Disqualified => 'You were disqualified from this competition.',
            self::NotYetOpen => 'Registration for this room has not opened yet.',
            self::RegistrationClosed => 'The registration window for this room has closed.',
            self::Full => 'All seats are taken. A seat may open if a player withdraws.',
            self::InProgress => 'This competition has already started.',
            self::Ended => 'This competition has finished.',
            self::Cancelled => 'This competition was cancelled. Any entry fees are refunded.',
            self::LoginRequired => 'You need an account to join a competition.',
            self::AccountRestricted => 'Your account cannot join competitions at the moment. Please contact support.',
            self::EmailNotVerified => 'Verify your email address before joining a competition.',
            self::PaidEntryUnavailable => 'Paid-entry competitions are not currently available.',
            self::InsufficientBalance => 'Your wallet balance is lower than the entry fee. You have not been charged.',
            self::PaymentFailed => 'Your entry fee could not be taken, so you were not charged and no seat was taken. Please try again or contact support.',
            self::JoiningUnavailable => 'Online joining is not available yet. It will be enabled in an upcoming release.',
        };
    }
}
