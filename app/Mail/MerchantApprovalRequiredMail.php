<?php

namespace App\Mail;

use App\Models\Merchant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantApprovalRequiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Merchant $merchant)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New merchant registration needs approval');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.merchant-approval-required');
    }
}
