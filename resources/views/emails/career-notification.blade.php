<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Career Application</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #677a58; color: #fff; padding: 20px; text-align: center;">
        <h1 style="margin: 0; color: #e8a030; font-size: 24px;">Sathish Design Studio</h1>
        <p style="margin: 5px 0 0 0; font-size: 14px;">New Career Application</p>
    </div>
    
    <div style="background-color: #f9f9f9; padding: 20px; margin-top: 20px; border-radius: 5px;">
        <h2 style="color: #677a58; margin-top: 0;">You have received a new career application</h2>
        
        <div style="background-color: #fff; padding: 15px; margin: 15px 0; border-left: 4px solid #677a58;">
            <p><strong>Full Name:</strong> {{ $career->full_name }}</p>
            <p><strong>Email:</strong> {{ $career->email }}</p>
            <p><strong>Mobile Number:</strong> {{ $career->mobile_number }}</p>
            <p><strong>Position Applied For:</strong> {{ $career->position_applied_for }}</p>
            @if($career->more_about_you)
            <p><strong>More About You:</strong></p>
            <p style="background-color: #f5f5f5; padding: 10px; border-radius: 3px; white-space: pre-wrap;">{{ $career->more_about_you }}</p>
            @endif
            @if($career->resume && isset($career->resume['name']))
            <p><strong>Resume:</strong> {{ $career->resume['name'] }} ({{ number_format($career->resume['size'] / 1024, 2) }} KB)</p>
            <p style="color: #666; font-size: 14px;"><em>Resume attached to this email.</em></p>
            @endif
        </div>
        
        <p style="color: #666; font-size: 14px;">Please review the application and contact the candidate at your earliest convenience.</p>
    </div>
    
    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
        <p>This is an automated notification from Sathish Design Studio</p>
        <p>Application submitted on: {{ $career->created_at->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>

