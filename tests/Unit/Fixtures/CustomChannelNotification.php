<?php

declare(strict_types=1);

namespace Marrow\Tests\Unit\Fixtures;

use Marrow\Notifications\Notification;

class CustomChannelNotification extends Notification
{
    public function __construct(private readonly string $channel)
    {
    }

    public function via(object $notifiable): array
    {
        return [$this->channel];
    }
}
