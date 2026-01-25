Thank You for Your Application - Sathish Design Studio

Dear {{ $career->full_name }},

Thank you for submitting your application for the position of {{ $career->position_applied_for }}. We have received your information and resume, and our team will review it shortly.

Application Summary:
- Position Applied For: {{ $career->position_applied_for }}
- Email: {{ $career->email }}
- Mobile Number: {{ $career->mobile_number }}

We will update you shortly regarding the status of your application. Our hiring team will review your qualifications and get back to you as soon as possible.

If you have any questions, please feel free to reach out to us at:
Email: {{ config('app.client_mail_id') }}

We appreciate your interest in joining our team and look forward to the possibility of working with you.

Best regards,
The Sathish Design Studio Team

---
This is an automated confirmation email from Sathish Design Studio
Application submitted on: {{ $career->created_at->format('d/m/Y h:i A') }}

