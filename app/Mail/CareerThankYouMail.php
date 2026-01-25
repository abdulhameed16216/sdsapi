<?php

namespace App\Mail;

use App\Models\Career;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CareerThankYouMail extends Mailable
{
    use Queueable, SerializesModels;

    public $career;

    /**
     * Create a new message instance.
     */
    public function __construct(Career $career)
    {
        $this->career = $career;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Thank You for Your Application - SDS',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'emails.career-thank-you',
            text: 'emails.career-thank-you-text',
            with: [
                'career' => $this->career,
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

