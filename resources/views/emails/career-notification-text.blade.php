New Career Application - Sathish Design Studio

You have received a new career application.

Application Details:
- Full Name: {{ $career->full_name }}
- Email: {{ $career->email }}
- Mobile Number: {{ $career->mobile_number }}
- Position Applied For: {{ $career->position_applied_for }}
@if($career->more_about_you)
- More About You: {{ $career->more_about_you }}
@endif
@if($career->resume && isset($career->resume['name']))
- Resume: {{ $career->resume['name'] }} ({{ number_format($career->resume['size'] / 1024, 2) }} KB)
  Resume attached to this email.
@endif

Please review the application and contact the candidate at your earliest convenience.

---
This is an automated notification from Sathish Design Studio
Application submitted on: {{ $career->created_at->format('d/m/Y h:i A') }}

