<?php

namespace App\Http\Controllers;

use App\Exceptions\AdministrativeActionException;
use App\Exceptions\EntryDeniedException;
use App\Models\Room;
use App\Services\Competition\RoomEntryService;
use App\Support\DisplayTime;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Player join/withdraw. The request carries only intent; seat counts, fees
 * and times all come from the server.
 */
class RoomEntryController extends Controller
{
    public function __construct(protected RoomEntryService $entries) {}

    public function join(Request $request, Room $room): RedirectResponse
    {
        $back = redirect()->route('competitions.show', $room);

        try {
            ['outcome' => $outcome, 'seat' => $seat] = $this->entries->join($request->user(), $room);
        } catch (EntryDeniedException $e) {
            return $back->with('error', $e->getMessage());
        }

        return match ($outcome) {
            RoomEntryService::JOINED => $back->with('success', $seat->entry_fee_minor > 0
                ? "You're in! Your seat is confirmed and ".Money::format($seat->entry_fee_minor, $room->currency)." was paid from your wallet (reference {$seat->payment_reference})."
                : "You're in! Your seat is confirmed."),
            RoomEntryService::RESERVED => $back->with('success', 'Seat reserved until '.DisplayTime::format($seat->reserved_until, 'g:i A').' '.DisplayTime::zoneLabel().'. Complete your entry payment before then or the seat is released.'),
            default => $back->with('success', 'You already have a seat in this room.'),
        };
    }

    public function withdraw(Request $request, Room $room): RedirectResponse
    {
        try {
            $this->entries->withdraw($request->user(), $room);
        } catch (AdministrativeActionException $e) {
            return redirect()->route('competitions.show', $room)->with('error', $e->getMessage());
        }

        $refunded = $room->participants()->where('user_id', $request->user()->id)->whereNotNull('refund_ledger_transaction_id')->whereNotNull('entry_ledger_transaction_id')->first();

        return redirect()->route('competitions.show', $room)->with('success', $refunded
            ? 'You have left this room. Your entry fee of '.Money::format($refunded->entry_fee_minor, $room->currency).' was refunded to your wallet.'
            : 'You have left this room. Your seat has been released.');
    }
}
