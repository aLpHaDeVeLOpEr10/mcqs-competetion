<?php

use App\Broadcasting\CompetitionChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live competition events; seated participants only.
Broadcast::channel('competition.{session}', CompetitionChannel::class);
