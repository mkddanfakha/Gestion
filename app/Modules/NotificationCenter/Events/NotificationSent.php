<?php

namespace App\Modules\NotificationCenter\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        public array $notification,
        public int $userId,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channel = str_replace(
            '{userId}',
            (string) $this->userId,
            config('notification-center.realtime.channel', 'user.{userId}.notifications')
        );

        return [new PrivateChannel($channel)];
    }

    public function broadcastAs(): string
    {
        return config('notification-center.realtime.event', 'notification.sent');
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->notification];
    }
}
