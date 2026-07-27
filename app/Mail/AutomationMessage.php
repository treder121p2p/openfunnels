<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AutomationMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $messageSubject,
        public string $messageBody,
        public ?string $replyToAddress = null,
    ) {}

    public function build(): self
    {
        $mail = $this
            ->subject($this->messageSubject)
            ->text('emails.automation-message');

        if ($this->replyToAddress) {
            $mail->replyTo($this->replyToAddress);
        }

        return $mail;
    }
}
