<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Your Quote Request</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #677a58; color: #fff; padding: 20px; text-align: center;">
        <h1 style="margin: 0; color: #e8a030; font-size: 24px;">Sathish Design Studio</h1>
        <p style="margin: 5px 0 0 0; font-size: 14px;">Thank You for Your Interest</p>
    </div>
    
    <div style="background-color: #f9f9f9; padding: 20px; margin-top: 20px; border-radius: 5px;">
        <h2 style="color: #677a58; margin-top: 0;">Dear {{ $quote->name }},</h2>
        
        @if($quote->request_type === 'contactus')
        <p>Thank you for contacting us. We have received your message and our team will review it shortly.</p>
        @else
        <p>Thank you for submitting your quote request. We have received your information and our team will review it shortly.</p>
        @endif
        
        <div style="background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #677a58;">
            <p style="margin: 0;"><strong>Request Summary:</strong></p>
            @if($quote->request_type === 'subscription' && $quote->subscription)
            <p style="margin: 5px 0;"><strong>Subscription Plan:</strong> {{ $quote->subscription->title }}</p>
            @elseif($quote->request_type === 'partner' && $quote->partnerProgram)
            <p style="margin: 5px 0;"><strong>Partner Program:</strong> {{ $quote->partnerProgram->name }}</p>
            @endif
            @if($quote->service)
            <p style="margin: 5px 0;"><strong>Service:</strong> {{ $quote->service }}</p>
            @endif
            @if($quote->project_type)
            <p style="margin: 5px 0;"><strong>Project Type:</strong> {{ $quote->project_type }}</p>
            @endif
            @if($quote->project_date)
            <p style="margin: 5px 0;"><strong>Project Date:</strong> {{ $quote->project_date->format('d/m/Y') }}</p>
            @endif
        </div>
        
        <p><strong>We will contact you shortly</strong> to discuss your requirements in detail and provide you with the best possible solution.</p>
        
        <p>If you have any urgent questions, please feel free to reach out to us at:</p>
        <p style="background-color: #fff; padding: 10px; border-radius: 3px;">
            <strong>Email:</strong> {{ config('app.client_mail_id') }}
        </p>
        
        <p style="margin-top: 30px;">We appreciate your interest in our services and look forward to working with you.</p>
        
        <p style="margin-top: 20px;">
            Best regards,<br>
            <strong>The Sathish Design Studio Team</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
        <p>This is an automated confirmation email from Sathish Design Studio</p>
        <p>Request submitted on: {{ $quote->created_at->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>

