Sathish Design Studio - New Quote Request
===========================================

You have received a new {{ $quote->request_type === 'subscription' ? 'subscription' : ($quote->request_type === 'partner' ? 'partner program' : 'quote') }} request.

Request Type: {{ ucfirst($quote->request_type ?? 'quote') }}
@if($quote->request_type === 'subscription' && $quote->subscription)
Subscription Plan: {{ $quote->subscription->title }}
@elseif($quote->request_type === 'partner' && $quote->partnerProgram)
Partner Program: {{ $quote->partnerProgram->name }}
@endif
Name: {{ $quote->name }}
Email: {{ $quote->email }}
Mobile Number: {{ $quote->mobile_number }}
@if($quote->service)
Service: {{ $quote->service }}
@endif
@if($quote->state)
State: {{ $quote->state }}
@endif
@if($quote->project_type)
Project Type: {{ $quote->project_type }}
@endif
@if($quote->budget)
Approx. Budget: {{ $quote->budget }}
@endif
@if($quote->project_date)
Project Date: {{ $quote->project_date->format('d/m/Y') }}
@endif
@if($quote->project_details)
Project Details:
{{ $quote->project_details }}
@endif
@if($quote->files && count($quote->files) > 0)
Uploaded Files:
@foreach($quote->files as $file)
- {{ $file['name'] }} ({{ number_format($file['size'] / 1024, 2) }} KB)
@endforeach
@endif

Please contact the customer at your earliest convenience.

Customer Contact:
Email: {{ $quote->email }}
Mobile: {{ $quote->mobile_number }}

---
This is an automated notification from Sathish Design Studio
Request submitted on: {{ $quote->created_at->format('d/m/Y h:i A') }}

