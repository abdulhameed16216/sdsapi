<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You for Your Application</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #677a58; color: #fff; padding: 20px; text-align: center;">
        <h1 style="margin: 0; color: #e8a030; font-size: 24px;">Sathish Design Studio</h1>
        <p style="margin: 5px 0 0 0; font-size: 14px;">Thank You for Your Interest</p>
    </div>
    
    <div style="background-color: #f9f9f9; padding: 20px; margin-top: 20px; border-radius: 5px;">
        <h2 style="color: #677a58; margin-top: 0;">Dear {{ $career->full_name }},</h2>
        
        <p>Thank you for submitting your application for the position of <strong>{{ $career->position_applied_for }}</strong>. We have received your information and resume, and our team will review it shortly.</p>
        
        <div style="background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #677a58;">
            <p style="margin: 0;"><strong>Application Summary:</strong></p>
            <p style="margin: 5px 0;"><strong>Position Applied For:</strong> {{ $career->position_applied_for }}</p>
            <p style="margin: 5px 0;"><strong>Email:</strong> {{ $career->email }}</p>
            <p style="margin: 5px 0;"><strong>Mobile Number:</strong> {{ $career->mobile_number }}</p>
        </div>
        
        <p><strong>We will update you shortly</strong> regarding the status of your application. Our hiring team will review your qualifications and get back to you as soon as possible.</p>
        
        <p>If you have any questions, please feel free to reach out to us at:</p>
        <p style="background-color: #fff; padding: 10px; border-radius: 3px;">
            <strong>Email:</strong> {{ config('app.client_mail_id') }}
        </p>
        
        <p style="margin-top: 30px;">We appreciate your interest in joining our team and look forward to the possibility of working with you.</p>
        
        <p style="margin-top: 20px;">
            Best regards,<br>
            <strong>The Sathish Design Studio Team</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
        <p>This is an automated confirmation email from Sathish Design Studio</p>
        <p>Application submitted on: {{ $career->created_at->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>

