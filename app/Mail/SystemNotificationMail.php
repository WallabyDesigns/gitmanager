<?php

namespace App\Mail;

use App\Services\EmailBrandingService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SystemNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{title: string, fields?: array<string, mixed>, error_log?: string|null}>  $items
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly string $intro,
        public readonly array $items = [],
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
        public readonly bool $showEnterpriseSuggestion = false,
    ) {}

    public function build(): self
    {
        $brand = app(EmailBrandingService::class)->resolve();

        return $this->subject($this->subjectLine)
            ->view('emails.system-notification')
            ->text('emails.system-notification-text')
            ->with('brand', $brand);
    }
}
