<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StockMutationRejectedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $supplier,
        public readonly array $failedItems,
    ) {
    }

    public function build(): self
    {
        return $this
            ->from(
                address: config('alerts.mail_from_address'),
                name: config('alerts.mail_from_name'),
            )
            ->subject('GoedGepickt stock mutations rejected - '.$this->supplier)
            ->view('emails.stock-mutation-rejected');
    }
}
