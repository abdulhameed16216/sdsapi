<?php

namespace App\Mail;

use App\Models\Career;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CareerNotificationMail extends Mailable
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
            subject: 'New Career Application - ' . $this->career->full_name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'emails.career-notification',
            text: 'emails.career-notification-text',
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
        $attachments = [];
        
        // Attach resume if it exists
        if ($this->career->resume && is_array($this->career->resume)) {
            try {
                // Use full_path if available, otherwise construct from path
                $filePath = isset($this->career->resume['full_path']) && file_exists($this->career->resume['full_path'])
                    ? $this->career->resume['full_path']
                    : (isset($this->career->resume['path']) ? public_path($this->career->resume['path']) : null);
                
                // Check if file exists before attaching
                if ($filePath && file_exists($filePath)) {
                    $attachments[] = Attachment::fromPath($filePath)
                        ->as($this->career->resume['name'] ?? basename($filePath)) // Original filename
                        ->withMime($this->career->resume['mime_type'] ?? mime_content_type($filePath));
                }
            } catch (\Exception $e) {
                // Log error but continue
                \Log::warning('Failed to attach resume to email: ' . $e->getMessage());
            }
        }
        
        return $attachments;
    }
}

