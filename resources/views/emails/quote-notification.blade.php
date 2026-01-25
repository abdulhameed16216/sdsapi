<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Quote Request</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #677a58; color: #fff; padding: 20px; text-align: center;">
        <h1 style="margin: 0; color: #e8a030; font-size: 24px;">Sathish Design Studio</h1>
        <p style="margin: 5px 0 0 0; font-size: 14px;">New {{ ucfirst($quote->request_type ?? 'quote') }} Request</p>
    </div>
    
    <div style="background-color: #f9f9f9; padding: 20px; margin-top: 20px; border-radius: 5px;">
        <h2 style="color: #677a58; margin-top: 0;">You have received a new {{ $quote->request_type === 'contactus' ? 'contact' : 'quote' }} request</h2>
        
        <div style="background-color: #fff; padding: 15px; margin: 15px 0; border-left: 4px solid #677a58;">
            <p><strong>Request Type:</strong> {{ ucfirst($quote->request_type ?? 'quote') }}</p>
            @if($quote->request_type === 'subscription' && $quote->subscription)
            <p><strong>Subscription Plan:</strong> {{ $quote->subscription->title }}</p>
            @elseif($quote->request_type === 'partner' && $quote->partnerProgram)
            <p><strong>Partner Program:</strong> {{ $quote->partnerProgram->name }}</p>
            @endif
            <p><strong>Name:</strong> {{ $quote->name }}</p>
            <p><strong>Email:</strong> {{ $quote->email }}</p>
            <p><strong>Mobile Number:</strong> {{ $quote->mobile_number }}</p>
            @if($quote->service)
            <p><strong>Service:</strong> {{ $quote->service }}</p>
            @endif
            @if($quote->state)
            <p><strong>State:</strong> {{ $quote->state }}</p>
            @endif
            @if($quote->project_type)
            <p><strong>Project Type:</strong> {{ $quote->project_type }}</p>
            @endif
            @if($quote->budget)
            <p><strong>Approx. Budget:</strong> {{ $quote->budget }}</p>
            @endif
            @if($quote->project_date)
            <p><strong>Project Date:</strong> {{ $quote->project_date->format('d/m/Y') }}</p>
            @endif
            @if($quote->project_details)
            <p><strong>Project Details:</strong></p>
            <p style="background-color: #f5f5f5; padding: 10px; border-radius: 3px; white-space: pre-wrap;">{{ $quote->project_details }}</p>
            @endif
            @if($quote->files && count($quote->files) > 0)
            <p><strong>Uploaded Files:</strong></p>
            <ul>
                @foreach($quote->files as $file)
                <li>{{ $file['name'] }} ({{ number_format($file['size'] / 1024, 2) }} KB)</li>
                @endforeach
            </ul>
            @endif
        </div>
        
        <p style="color: #666; font-size: 14px;">Please contact the customer at your earliest convenience.</p>
    </div>
    
    <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 12px;">
        <p>This is an automated notification from Sathish Design Studio</p>
        <p>Request submitted on: {{ $quote->created_at->format('d/m/Y h:i A') }}</p>
    </div>
</body>
</html>

