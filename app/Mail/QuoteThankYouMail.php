<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteThankYouMail extends Mailable
{
    use Queueable, SerializesModels;

    public $quote;

    /**
     * Create a new message instance.
     */
    public function __construct(Quote $quote)
    {
        $this->quote = $quote;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subjectMap = [
            'contactus' => 'Thank You for Contacting Us - SDS',
            'subscription' => 'Thank You for Your Subscription Request - SDS',
            'partner' => 'Thank You for Your Partner Program Request - SDS',
            'quote' => 'Thank You for Your Quote Request - SDS'
        ];
        $subject = $subjectMap[$this->quote->request_type] ?? 'Thank You for Your Request - SDS';
        return new Envelope(
            subject: $subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'emails.quote-thank-you',
            text: 'emails.quote-thank-you-text',
            with: [
                'quote' => $this->quote,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
