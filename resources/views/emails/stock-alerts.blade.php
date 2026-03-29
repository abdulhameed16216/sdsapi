<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Alert Notification</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 5px 5px 0 0;
        }
        .content {
            background: #f8f9fa;
            padding: 20px;
            border: 1px solid #dee2e6;
        }
        .alert-section {
            margin-bottom: 30px;
        }
        .alert-section h3 {
            color: #007bff;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .alert-item {
            background: white;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
        }
        .alert-item.critical {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .alert-item.low {
            border-left-color: #ffc107;
            background: #fffbf0;
        }
        .alert-product {
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
        }
        .alert-details {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .alert-stock {
            display: block;
            margin-top: 5px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #666;
            font-size: 12px;
            border-top: 1px solid #dee2e6;
            margin-top: 20px;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-critical {
            background: #dc3545;
            color: white;
        }
        .badge-low {
            background: #ffc107;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚠️ Stock Alert Notification</h1>
        <p>Low Stock Products Detected</p>
    </div>
    
    <div class="content">
        <p>Dear Admin,</p>
        <p>The following products have fallen below their minimum threshold levels:</p>
        
        @if(count($customerAlerts) > 0)
        <div class="alert-section">
            <h3>📦 Customer Stock Alerts ({{ count($customerAlerts) }})</h3>
            @foreach($customerAlerts as $alert)
            <div class="alert-item {{ $alert['alert_level'] }}">
                <div class="alert-product">
                    {{ $alert['product_name'] }} ({{ $alert['product_code'] }})
                    <span class="badge badge-{{ $alert['alert_level'] }}">
                        {{ strtoupper($alert['alert_level']) }}
                    </span>
                </div>
                <div class="alert-details">
                    <strong>Customer:</strong> {{ $alert['customer_name'] ?? 'Unknown' }}<br>
                    <span class="alert-stock">
                        <strong>Current Stock:</strong> {{ $alert['current_stock'] }}<br>
                        <strong>Threshold:</strong> {{ $alert['threshold'] }}<br>
                        <strong>Percentage:</strong> {{ number_format($alert['percentage'], 2) }}% of threshold
                    </span>
                </div>
            </div>
            @endforeach
        </div>
        @endif
        
        @if(count($internalAlerts) > 0)
        <div class="alert-section">
            <h3>🏭 Internal Stock Alerts ({{ count($internalAlerts) }})</h3>
            @foreach($internalAlerts as $alert)
            <div class="alert-item {{ $alert['alert_level'] }}">
                <div class="alert-product">
                    {{ $alert['product_name'] }} ({{ $alert['product_code'] }})
                    <span class="badge badge-{{ $alert['alert_level'] }}">
                        {{ strtoupper($alert['alert_level']) }}
                    </span>
                </div>
                <div class="alert-details">
                    <span class="alert-stock">
                        <strong>Current Stock:</strong> {{ $alert['current_stock'] }}<br>
                        <strong>Threshold:</strong> {{ $alert['threshold'] }}<br>
                        <strong>Percentage:</strong> {{ number_format($alert['percentage'], 2) }}% of threshold
                    </span>
                </div>
            </div>
            @endforeach
        </div>
        @endif
        
        <p><strong>Total Alerts:</strong> {{ $totalAlerts }}</p>
        
        <p style="margin-top: 20px;">
            Please take necessary action to restock these products to maintain optimal inventory levels.
        </p>
    </div>
    
    <div class="footer">
        <p>This is an automated notification from EBMS (Everyday Beverages Management System)</p>
        <p>Generated on: {{ date('Y-m-d H:i:s') }}</p>
    </div>
</body>
</html>

