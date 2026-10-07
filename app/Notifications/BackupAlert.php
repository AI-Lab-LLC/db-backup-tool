<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plain-text operational alert (backup failure / stale database), sent on demand
 * to config('backup.alert_email'). Deliberately NOT queued: alerts must not
 * depend on the same queue worker whose failure they may be reporting.
 */
class BackupAlert extends Notification
{
    use Queueable;

    /**
     * @param string[] $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
    ) {
    }

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->error()
            ->subject($this->subject);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message;
    }
}
