<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
</head>
<body>
    <h1>Password Reset Request</h1>
    <p>To reset your password, click the link below:</p>
    <p><a href="{{ url('/password/reset/' . $token) }}">Reset Password</a></p>
    <p>If you did not request a password reset, please ignore this email.</p>
</body>
</html>
