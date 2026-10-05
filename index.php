<?php
// Route API and direct requests to the backend
if (strpos($_SERVER['REQUEST_URI'], '/api') === 0 ||
    strpos($_SERVER['REQUEST_URI'], '/storage') === 0 ||
    strpos($_SERVER['REQUEST_URI'], '/login') === 0 ||
    $_SERVER['REQUEST_URI'] === '/' ||
    pathinfo($_SERVER['REQUEST_URI'], PATHINFO_EXTENSION) === 'php') {

    // Include the Laravel backend
    require __DIR__ . '/backend/public/index.php';
} else {
    // Check if the file exists in the dist folder
    $distFile = __DIR__ . '/frontend/dist' . $_SERVER['REQUEST_URI'];

    if (file_exists($distFile) && is_file($distFile)) {
        // Serve the static file
        $mimeType = mime_content_type($distFile);
        header('Content-Type: ' . $mimeType);
        readfile($distFile);
        exit;
    } elseif (is_dir($distFile) && file_exists($distFile . '/index.html')) {
        // Serve index.html for directories
        header('Content-Type: text/html');
        readfile($distFile . '/index.html');
        exit;
    } else if (file_exists(__DIR__ . '/frontend/dist/index.html')) {
        // For SPA routing, serve the main index.html
        header('Content-Type: text/html');
        readfile(__DIR__ . '/frontend/dist/index.html');
        exit;
    } else {
        // No dist build - try to proxy to Vite dev server
        $url = 'http://localhost:5173' . $_SERVER['REQUEST_URI'];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        http_response_code($httpCode);
        if ($contentType) {
            header('Content-Type: ' . $contentType);
        }
        echo $response;
        exit;
    }
}
