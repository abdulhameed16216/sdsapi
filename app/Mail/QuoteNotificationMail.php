<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteNotificationMail extends Mailable
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
        $requestTypeMap = [
            'contactus' => 'Contact',
            'subscription' => 'Subscription',
            'partner' => 'Partner Program',
            'quote' => 'Quote'
        ];
        $requestType = $requestTypeMap[$this->quote->request_type] ?? 'Request';
        return new Envelope(
            subject: 'New ' . $requestType . ' Request - ' . $this->quote->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'emails.quote-notification',
            text: 'emails.quote-notification-text',
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
        $attachments = [];
        
        // Attach files if they exist
        if ($this->quote->files && is_array($this->quote->files) && count($this->quote->files) > 0) {
            foreach ($this->quote->files as $file) {
                try {
                    // Use full_path if available, otherwise construct from path
                    $filePath = isset($file['full_path']) && file_exists($file['full_path'])
                        ? $file['full_path']
                        : (isset($file['path']) ? public_path($file['path']) : null);
                    
                    // Check if file exists before attaching
                    if ($filePath && file_exists($filePath)) {
                        $attachments[] = Attachment::fromPath($filePath)
                            ->as($file['name'] ?? basename($filePath)) // Original filename
                            ->withMime($file['mime_type'] ?? mime_content_type($filePath));
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other files
                    \Log::warning('Failed to attach file to email: ' . $e->getMessage());
                }
            }
        }
        
        return $attachments;
    }
}
