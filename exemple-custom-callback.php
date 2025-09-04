<?php
// Get OAuth parameters from Microsoft
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';
$error_description = $_GET['error_description'] ?? '';

// Decode the state to retrieve the client site URL
$client_site = '';
if ($state) {
    $state_data = json_decode(base64_decode($state), true);
    if ($state_data && isset($state_data['client_site'])) {
        $client_site = $state_data['client_site'];
    }
}

// If no client site, error
if (empty($client_site)) {
    die('Error: Client site not found in parameters');
}

// Build the return URL to the client's WordPress site
$callback_url = rtrim($client_site, '/') . '/wp-admin/options-general.php?page=mailwp-settings&oauth_callback=1';

// Add all OAuth parameters
$params = [];
if ($code) $params['code'] = $code;
if ($state) $params['state'] = $state;
if ($error) $params['error'] = $error;
if ($error_description) $params['error_description'] = $error_description;

$final_url = $callback_url . '&' . http_build_query($params);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Microsoft Authentication</title>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; text-align: center; margin-top: 100px; }
        .container { max-width: 400px; margin: 0 auto; padding: 20px; }
        .success { color: #28a745; font-size: 48px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✅</div>
        <h2>Microsoft Authentication Successful!</h2>
        <p>Redirecting to your site...</p>
        <p style="font-size: 12px; color: #666;">Site: <?php echo htmlspecialchars($client_site); ?></p>
    </div>
    
    <script>
        setTimeout(function() {
            window.location.href = '<?php echo addslashes($final_url); ?>';
        }, 2000);
    </script>
</body>
</html>