<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\EmallNotification;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\Notification;

/** Outbound copy of a notice to Dot.Notify. Does nothing unless NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET are set. */
class DotNotifyChannel
{
    public function __construct(private readonly NotifyClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if ($notifiable instanceof User && $notification instanceof EmallNotification) {
            $this->client->send($notifiable, class_basename($notification), (string) ($notification->id ?? spl_object_id($notification)), $notification->toNotify($notifiable));
        }
    }
}
