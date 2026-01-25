Sathish Design Studio - Thank You for Your Quote Request
=========================================================

Dear {{ $quote->name }},

Thank you for submitting your quote request. We have received your information and our team will review it shortly.

Request Summary:
@if($quote->request_type === 'subscription' && $quote->subscription)
Subscription Plan: {{ $quote->subscription->title }}
@elseif($quote->request_type === 'partner' && $quote->partnerProgram)
Partner Program: {{ $quote->partnerProgram->name }}
@endif
@if($quote->service)
Service: {{ $quote->service }}
@endif
@if($quote->project_type)
Project Type: {{ $quote->project_type }}
@endif
@if($quote->project_date)
Project Date: {{ $quote->project_date->format('d/m/Y') }}
@endif

We will contact you shortly to discuss your requirements in detail and provide you with the best possible solution.

If you have any urgent questions, please feel free to reach out to us at:
Email: {{ config('app.client_mail_id') }}

We appreciate your interest in our services and look forward to working with you.

Best regards,
The Sathish Design Studio Team

---
This is an automated confirmation email from Sathish Design Studio
Request submitted on: {{ $quote->created_at->format('d/m/Y h:i A') }}

