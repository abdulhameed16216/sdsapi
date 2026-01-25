<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Access Error</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            max-width: 600px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            text-align: center;
        }
        
        .error-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 24px;
            font-weight: 600;
            color: #dc3545;
            margin-bottom: 15px;
        }
        
        .error-message {
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: left;
        }
        
        .user-info h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        
        .user-info p {
            font-size: 14px;
            color: #666;
            margin: 5px 0;
        }
        
        .document-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: left;
        }
        
        .document-info h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        
        .document-info p {
            font-size: 14px;
            color: #666;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <div class="error-title">Access Denied</div>
        <div class="error-message">{{ $error }}</div>
        
        @if($user)
        <div class="user-info">
            <h3>Current User:</h3>
            <p><strong>Name:</strong> {{ $user['name'] }}</p>
            <p><strong>Email:</strong> {{ $user['email'] }}</p>
            <p><strong>Type:</strong> {{ ucfirst($user['type']) }}</p>
        </div>
        @endif
        
        @if($document)
        <div class="document-info">
            <h3>Document Information:</h3>
            <p><strong>Name:</strong> {{ $document->name }}</p>
            <p><strong>File Name:</strong> {{ $document->file_name }}</p>
            <p><strong>Project ID:</strong> {{ $document->project_id }}</p>
        </div>
        @endif
    </div>
</body>
</html>

